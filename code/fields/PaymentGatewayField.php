<?php
/**
 * PaymentGatewayField.php
 *
 * @author Bram de Leeuw
 * Date: 10/03/17
 */

namespace Broarm\EventTickets;

use FieldGroup;
use FieldList;
use HiddenField;
use LiteralField;
use OptionsetField;
use SilverStripe\Omnipay\GatewayInfo;

/**
 * Class PaymentGatewayField
 *
 * @package Broarm\EventTickets
 */
class PaymentGatewayField extends FieldGroup
{
    /**
     * Construct the payment gateway select
     * This could render the following
     * 1. Selectable gateways as an option set
     * 2. Hidden field with the only configured gateway
     * 3. Message when no gateway is configured
     *
     * PaymentGatewayField constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $children = FieldList::create();

        if ($gateways = GatewayInfo::getSupportedGateways(true)) {
            $this->extend('updateGateways', $gateways);

            if (count($gateways) > 1) {
                $children->add(OptionsetField::create(
                    'Gateway',
                    _t('PaymentGatewayField.SELECT_GATEWAY', 'Select a gateway'),
                    $gateways
                )->setValue(key($gateways)));
            } else {
                $children->add(HiddenField::create('Gateway', 'Gateway', key($gateways)));
            }
        } else {
            $children->add(
                LiteralField::create('NoGateway', "<p>No gateways configured</p>")
            );
        }

        $this->extend('updateGatewayField');
        $this->setChildren($children);
    }
}