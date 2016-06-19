<?php
/**
 * @package       RSMembership!
 * @copyright (C) 2009-2014 www.rsjoomla.com
 * @license       GPL, http://www.gnu.org/licenses/gpl-2.0.html
 */
/**
 * @plugin RSMembership Zarinpal Payment
 * @author Mohsen Ranjbar(mimrahe)
 * @authorEmail mimrahe@gmail.com
 * @authorUrl http://mimrahe.com
 */

ini_set('display_errors', 1);
defined('_JEXEC') or die('Restricted access');
require_once JPATH_ADMINISTRATOR . '/components/com_rsmembership/helpers/rsmembership.php';

class plgSystemRSMembershipZarinPal extends JPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        // load languages
        $this->loadLanguage('plg_system_rsmembership', JPATH_ADMINISTRATOR);
        $this->loadLanguage('plg_system_rsmembershipzarinpal', JPATH_ADMINISTRATOR);

        RSMembership::addPlugin($this->translate('nice_name'), 'rsmembershipzarinpal');
    }

    /**
     * call when payment starts
     *
     * @param $plugin
     * @param $data
     * @param $extra
     * @param $membership
     * @param $transaction
     * @param $html
     */
    public function onMembershipPayment($plugin, $data, $extra, $membership, $transaction, $html)
    {
        $app = JFactory::getApplication();
        try {
            if ($plugin != 'rsmembershipzarinpal')
                return;

            $MerchantID = trim($this->params->get('merchant_id'));
            $extra_total = 0;
            foreach ($extra as $row) {
                $extra_total += $row->price;
            }

            $Amount = $this->zarinPalAmount($transaction->price + $extra_total);

            $Description = $membership->name;
            $Description = $this->escape($Description);

            $Email = $data->email;
            $Mobile = '';

            $transaction->custom = md5($transaction->params . ' ' . time());
            if ($membership->activation == 2) {
                $transaction->status = 'completed';
            }
            $transaction->store();

            $CallbackURL = JURI::base() . 'index.php?option=com_rsmembership&zarinpalPayment=1&amount=' . $Amount;
            $CallbackURL = JRoute::_($CallbackURL, false);
            $session =& JFactory::getSession();
            $session->set('transaction_custom', $transaction->custom);
            $session->set('membership_id', $membership->id);

            $requestContext = compact(
                'MerchantID',
                'Amount',
                'Description',
                'Email',
                'Mobile',
                'CallbackURL'
            );

            $request = $this->zarinPalRequest('request', $requestContext);

            if (!$request)
                throw new Exception('connection_error');

            $status = $request->Status;

            if ($status == 100) {
                $prefix = (bool)$this->params->get('test_mode') ? 'sandbox' : 'www';
                $postfix = (bool)$this->params->get('gate_type') ? '/ZarinGate' : '';
                $Authority = $request->Authority;
                $app->redirect("https://{$prefix}.zarinpal.com/pg/StartPay/{$Authority}{$postfix}");
            }

            throw new Exception('status_' . $status);

        } catch (Exception $e) {
            $message = $this->translate('error_title') . '<br>' . $this->translate($e->getMessage());
            $app->redirect(JRoute::_(JURI::base() . 'index.php/component/rsmembership/view-membership-details/' . $membership->id, false), $message, 'warning');
            exit;
        }
    }


    /**
     * after payment completed
     * calls function onPaymentNotification()
     */
    public function onAfterDispatch()
    {
        $app = JFactory::getApplication();
        if ($app->input->getBoolean('zarinpalPayment')) {
            $this->onPaymentNotification($app);
        }
    }

    /**
     * process payment verification and approve subscription
     * @param $app
     */
    protected function onPaymentNotification($app)
    {
        $input = $app->input;
        $session =& JFactory::getSession();

        $transaction_custom = $session->get('transaction_custom');

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->quoteName('#__rsmembership_transactions'))
            ->where($db->quoteName('status') . ' != ' . $db->quote('completed'))
            ->where($db->quoteName('custom') . ' = ' . $db->quote($transaction_custom));
        $db->setQuery($query);
        $transaction = @$db->loadObject();

        try {
            if (!$transaction)
                throw new Exception('transaction_not_found', 1);

            if ($input->getString('Status') != 'OK')
                throw new Exception('payment_failed');

            $MerchantID = $this->params->get('merchant_id');
            $Authority = $input->getString('Authority');
            $Amount = $input->getInt('amount');

            $verifyContext = compact('MerchantID', 'Authority', 'Amount');
            $verify = $this->zarinPalRequest('verification', $verifyContext);

            if (!$verify)
                throw new Exception('connection_error');

            $status = $verify->Status;

            if ($status == 100) {
                $RefID = $verify->RefID;

                $query->clear();
                $query->update($db->quoteName('#__rsmembership_transactions'))
                    ->set($db->quoteName('hash') . ' = ' . $db->quote($RefID))
                    ->where($db->quoteName('id') . ' = ' . $db->quote($transaction->id));

                $db->setQuery($query);
                $db->execute();

                $membership_id = $session->get('membership_id');

                if (!$membership_id)
                    throw new Exception('membership_not_found');

                $query->clear()
                    ->select('activation')
                    ->from($db->quoteName('#__rsmembership_memberships'))
                    ->where($db->quoteName('id') . ' = ' . $db->quote((int)$membership_id));
                $db->setQuery($query);
                $activation = $db->loadResult();

                if ($activation) // activation == 0 => activation is manual
                {
                    RSMembership::approve($transaction->id);
                }

                $message = sprintf($this->translate('payment_succeed'), $RefID);

                $app->redirect(JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false), $message, 'message');
                die();
            }

            throw new Exception('status_' . $status);

        } catch (Exception $e) {
            if (!$e->getCode()) // means transaction found but should be denied
            {
                RSMembership::deny($transaction->id);
            }

            $message = $this->translate('error_title') . '<br>' . $this->translate($e->getMessage());
            $app->enqueueMessage($message, 'warning');
        }
    }

    /**
     * escape string
     * @param $string
     *
     * @return string
     */
    protected function escape($string)
    {
        return htmlentities($string, ENT_COMPAT, 'utf-8');
    }

    /**
     * translate plugin language files
     * @param $key
     * @return mixed
     */
    protected function translate($key)
    {
        $key = 'PLG_RSM_ZARINPAL_' . strtoupper($key);
        return JText::_($key);
    }


    /**
     * @param $type
     * @param $context
     * @return bool | soap client result
     */
    private function zarinPalRequest($type, $context)
    {
        try {
            $prefix = $this->params->get('test_mode') ? 'sandbox' : 'www';
            $client = new SoapClient("https://{$prefix}.zarinpal.com/pg/services/WebGate/wsdl", array('encoding' => 'UTF-8'));

            $type = 'Payment' . ucfirst($type);
            $result = $client->$type($context);

            return $result;
        } catch (SoapFault $e) {
            return false;
        }
    }

    /**
     * fix zarinpal amount
     *
     * @param $amount
     *
     * @return int
     */
    private function zarinPalAmount($amount)
    {
        $currency = $this->params->get('currency');
        if ($currency) { // currency == 1 => rial
            $amount /= 10;
        }

        return $amount;
    }

}