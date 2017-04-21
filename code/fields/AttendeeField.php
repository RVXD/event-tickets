<?php
/**
 * AttendeesField.php
 *
 * @author Bram de Leeuw
 * Date: 10/03/17
 */

namespace Broarm\EventTickets;

use ArrayList;
use CompositeField;
use DBField;
use FieldGroup;
use FieldList;
use FormField;
use HiddenField;
use LiteralField;

/**
 * Class AttendeeField
 *
 * @package Broarm\EventTickets
 */
class AttendeeField extends CompositeField
{
    private static $required_fields = array(
        'FirstName',
        'Surname',
        'Email'
    );

    protected $name = 'Attendee';

    protected $requiredFields = array();

    public function __construct(Attendee $attendee, $main = false, $required = true)
    {
        parent::__construct();
        $this->setTag('fieldset');
        $this->setLegend("{$attendee->Ticket()->Title} t.w.v {$attendee->Ticket()->getPriceNice()}");
        if ($required) $this->addExtraClass('required');

        $children = FieldList::create();
        $savableFields = Attendee::config()->get('savable_fields');
        foreach ($savableFields as $field => $fieldClass) {
            $fieldName = "{$this->name}[{$attendee->ID}][$field]";
            if (in_array($field, self::config()->get('required_fields')) && $required) {
                $this->addRequiredField($fieldName);
            }

            $children->add(
                $fieldClass::create($fieldName, _t("AttendeesField.$field", $field))
            );
        }

        // Set the main field
        $children->add(HiddenField::create("{$this->name}[{$attendee->ID}][Main]", 'Main', (int)$main));

        // set the children
        $this->setChildren($children);

        // Add a hook to modify the added fields if needed
        $this->extend('updateAttendeeField');
    }

    /**
     * Update the required fields array
     *
     * @param $fieldName
     */
    private function addRequiredField($fieldName) {
        array_push($this->requiredFields, $fieldName);
    }

    /**
     * Get the required fields
     *
     * @return array
     */
    public function getRequiredFields()
    {
        return $this->requiredFields;
    }
}
