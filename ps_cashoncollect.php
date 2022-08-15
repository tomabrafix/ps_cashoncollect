<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Ps_Cashoncollect extends PaymentModule
{
    const HOOKS = [
        'displayOrderConfirmation',
        'paymentOptions',
    ];

    const CONFIG_OS_CASH_ON_COLLECT = 'PS_OS_COC_VALIDATION';

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        $this->name = 'ps_cashoncollect';
        $this->tab = 'payments_gateways';
        $this->author = 'PrestaShop';
        $this->version = '2.0.1';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->controllers = ['validation'];
        $this->currencies = false;

        parent::__construct();

        $this->displayName = $this->trans('Cash on Collect', [], 'Modules.Cashoncollect.Admin');
        $this->description = $this->trans('Accept cash payments on collection to make it easy for customers to purchase on your store.', [], 'Modules.Cashoncollect.Admin');
    }

    /**
     * {@inheritdoc}
     */
    public function install()
    {
        return parent::install()
            && (bool) $this->registerHook(static::HOOKS)
            && $this->installOrderState();
    }

    /**
     * @param array{cookie: Cookie, cart: Cart, altern: int} $params
     *
     * @return array|PaymentOption[] Should always returns an array to avoid issue
     */
    public function hookPaymentOptions(array $params)
    {
        if (empty($params['cart'])) {
            return [];
        }

        /** @var Cart $cart */
        $cart = $params['cart'];

        if ($cart->isVirtualCart()) {
            return [];
        }

        $cashOnCollectOption = new PaymentOption();
        $cashOnCollectOption->setModuleName($this->name);
        $cashOnCollectOption->setCallToActionText($this->trans('Pay by Cash on Collect', [], 'Modules.Cashoncollect.Shop'));
        $cashOnCollectOption->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true));
        $cashOnCollectOption->setAdditionalInformation($this->fetch('module:ps_cashoncollect/views/templates/hook/paymentOptions-additionalInformation.tpl'));

        return [$cashOnCollectOption];
    }

    /**
     * @param array{cookie: Cookie, cart: Cart, altern: int, order: Order, objOrder: Order} $params
     *
     * @return string
     */
    public function hookDisplayOrderConfirmation(array $params)
    {
        /** @var Order $order */
        $order = (isset($params['objOrder'])) ? $params['objOrder'] : $params['order'];

        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $this->context->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'total' => $this->context->getCurrentLocale()->formatPrice($params['order']->getOrdersTotalPaid(), (new Currency($params['order']->id_currency))->iso_code),
            'reference' => $order->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true),
        ]);

        return $this->fetch('module:ps_cashoncollect/views/templates/hook/displayOrderConfirmation.tpl');
    }

    /**
     * @return bool
     */
    public function installOrderState()
    {
        if (Configuration::getGlobalValue(Ps_Cashoncollect::CONFIG_OS_CASH_ON_COLLECT)) {
            $orderState = new OrderState((int) Configuration::getGlobalValue(Ps_Cashoncollect::CONFIG_OS_CASH_ON_COLLECT));

            if (Validate::isLoadedObject($orderState) && $this->name === $orderState->module_name) {
                return true;
            }
        }

        return $this->createOrderState(
            static::CONFIG_OS_CASH_ON_COLLECT,
            [
                'en' => 'Awaiting Cash On Collect validation',
                'de' => 'Warten auf Zahlungseingang Barzahlung',
            ],
            true === (bool) version_compare(_PS_VERSION_, '1.7.7.0', '>=') ? '#4169E1' : '#34219E'
        );
    }

    /**
     * Create custom OrderState used for payment
     *
     * @param string $configurationKey Configuration key used to store OrderState identifier
     * @param array $nameByLangIsoCode An array of name for all languages, default is en
     * @param string $color Color of the label
     * @param bool $isLogable consider the associated order as validated
     * @param bool $isPaid set the order as paid
     * @param bool $isInvoice allow a customer to download and view PDF versions of his/her invoices
     * @param bool $isShipped set the order as shipped
     * @param bool $isDelivery show delivery PDF
     * @param bool $isPdfDelivery attach delivery slip PDF to email
     * @param bool $isPdfInvoice attach invoice PDF to email
     * @param bool $isSendEmail send an email to the customer when his/her order status has changed
     * @param string $template Only letters, numbers and underscores are allowed. Email template for both .html and .txt
     * @param bool $isHidden hide this status in all customer orders
     * @param bool $isUnremovable Disallow delete action for this OrderState
     * @param bool $isDeleted Set OrderState deleted
     *
     * @return bool
     */
    private function createOrderState(
        $configurationKey,
        array $nameByLangIsoCode,
        $color,
        $isLogable = false,
        $isPaid = false,
        $isInvoice = false,
        $isShipped = false,
        $isDelivery = false,
        $isPdfDelivery = false,
        $isPdfInvoice = false,
        $isSendEmail = false,
        $template = '',
        $isHidden = false,
        $isUnremovable = true,
        $isDeleted = false
    ) {
        $tabNameByLangId = [];

        foreach ($nameByLangIsoCode as $langIsoCode => $name) {
            foreach (Language::getLanguages(false) as $language) {
                if (Tools::strtolower($language['iso_code']) === $langIsoCode) {
                    $tabNameByLangId[(int) $language['id_lang']] = $name;
                } elseif (isset($nameByLangIsoCode['en'])) {
                    $tabNameByLangId[(int) $language['id_lang']] = $nameByLangIsoCode['en'];
                }
            }
        }

        $orderState = new OrderState();
        $orderState->module_name = $this->name;
        $orderState->name = $tabNameByLangId;
        $orderState->color = $color;
        $orderState->logable = $isLogable;
        $orderState->paid = $isPaid;
        $orderState->invoice = $isInvoice;
        $orderState->shipped = $isShipped;
        $orderState->delivery = $isDelivery;
        $orderState->pdf_delivery = $isPdfDelivery;
        $orderState->pdf_invoice = $isPdfInvoice;
        $orderState->send_email = $isSendEmail;
        $orderState->hidden = $isHidden;
        $orderState->unremovable = $isUnremovable;
        $orderState->template = $template;
        $orderState->deleted = $isDeleted;
        $result = (bool) $orderState->add();

        if (false === $result) {
            $this->_errors[] = sprintf(
                'Failed to create OrderState %s',
                $configurationKey
            );

            return false;
        }

        $result = (bool) Configuration::updateGlobalValue($configurationKey, (int) $orderState->id);

        if (false === $result) {
            $this->_errors[] = sprintf(
                'Failed to save OrderState %s to Configuration',
                $configurationKey
            );

            return false;
        }

        $orderStateImgPath = $this->getLocalPath() . 'views/img/orderstate/' . $configurationKey . '.gif';

        if (false === (bool) Tools::file_exists_cache($orderStateImgPath)) {
            $this->_errors[] = sprintf(
                'Failed to find icon file of OrderState %s',
                $configurationKey
            );

            return false;
        }

        if (false === (bool) Tools::copy($orderStateImgPath, _PS_ORDER_STATE_IMG_DIR_ . $orderState->id . '.gif')) {
            $this->_errors[] = sprintf(
                'Failed to copy icon of OrderState %s',
                $configurationKey
            );

            return false;
        }

        return true;
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }
}
