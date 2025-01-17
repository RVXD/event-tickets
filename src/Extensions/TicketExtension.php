<?php

namespace Broarm\EventTickets\Extensions;

use Broarm\EventTickets\Controllers\CheckInController;
use Broarm\EventTickets\Forms\GridField\GuestListGridFieldConfig;
use Broarm\EventTickets\Forms\GridField\ReservationGridFieldConfig;
use Broarm\EventTickets\Forms\GridField\TicketsGridFieldConfig;
use Broarm\EventTickets\Forms\GridField\UserFieldsGridFieldConfig;
use Broarm\EventTickets\Forms\GridField\WaitingListGridFieldConfig;
use Broarm\EventTickets\Model\Attendee;
use Broarm\EventTickets\Model\Reservation;
use Broarm\EventTickets\Model\Ticket;
use Broarm\EventTickets\Model\UserFields\UserField;
use Broarm\EventTickets\Model\WaitingListRegistration;
use DateTime;
use Exception;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Class TicketExtension
 *
 * @package Broarm\EventTickets
 *
 * @property TicketExtension|SiteTree $owner
 * @property int Capacity
 * @property int OrderMin
 * @property int OrderMax
 * @property string SuccessMessage
 * @property string SuccessMessageMail
 * @property string PrintedTicketContent
 *
 * @method HasManyList Tickets()
 * @method HasManyList Reservations()
 * @method HasManyList Attendees()
 * @method HasManyList WaitingList()
 * @method ManyManyList Fields()
 */
class TicketExtension extends DataExtension
{
    protected $controller;

    private static $db = array(
        'Capacity' => 'Int',
        'OrderMin' => 'Int',
        'OrderMax' => 'Int',
        'SuccessMessage' => 'HTMLText',
        'SuccessMessageMail' => 'HTMLText',
        'PrintedTicketContent' => 'HTMLText',
    );

    private static $has_many = array(
        'Tickets' => Ticket::class . '.TicketPage',
        'Reservations' => Reservation::class . '.TicketPage',
        'Attendees' => Attendee::class . '.TicketPage',
        'WaitingList' => WaitingListRegistration::class . '.TicketPage',
    );

    private static $many_many = array(
        'Fields' => UserField::class
    );

    private static $many_many_extraFields = [
        'Fields' => [
            'Sort' => 'Int'
        ]
    ];

    private static $defaults = array(
        'Capacity' => 50
    );

    private static $translate = array(
        'SuccessMessage',
        'SuccessMessageMail'
    );

    protected $cachedGuestList;

    public function updateCMSFields(FieldList $fields)
    {
        $guestListStatusDescription = _t(__CLASS__ . '.GuestListStatusDescription', 'Tickets sold: {guestListStatus}', null, [
            'guestListStatus' => $this->owner->getGuestListStatus()
        ]);

        $fields->addFieldsToTab('Root.Main', [
            LiteralField::create('GuestListStatus', "<p class='message notice'>{$guestListStatusDescription}</p>")
        ], 'Title');

        $ticketLabel = _t(__CLASS__ . '.Tickets', 'Tickets');
        $fields->addFieldsToTab(
            "Root.$ticketLabel", array(
            GridField::create('Tickets', $ticketLabel, $this->owner->Tickets(), TicketsGridFieldConfig::create()),
            NumericField::create('Capacity', _t(__CLASS__ . '.Capacity', 'Capacity')),
            HtmlEditorField::create('SuccessMessage', _t(__CLASS__ . '.SuccessMessage', 'Success message'))->addExtraClass('stacked')->setRows(4),
            HtmlEditorField::create('SuccessMessageMail', _t(__CLASS__ . '.MailMessage', 'Mail message'))->addExtraClass('stacked')->setRows(4),
            HtmlEditorField::create('PrintedTicketContent', _t(__CLASS__ . '.PrintedTicketContent', 'Ticket description'))->addExtraClass('stacked')->setRows(4)
        ));

        // Create Reservations tab
        if ($this->owner->Reservations()->exists()) {
            $reservationLabel = _t(__CLASS__ . '.Reservations', 'Reservations');
            $fields->addFieldToTab(
                "Root.$reservationLabel",
                GridField::create('Reservations', $reservationLabel, $this->owner->Reservations(), ReservationGridFieldConfig::create())
            );
        }

        // Create Attendees tab
        if ($this->owner->Attendees()->exists()) {
            $guestListLabel = _t(__CLASS__ . '.GuestList', 'GuestList');
            $fields->addFieldToTab(
                "Root.$guestListLabel",
                GridField::create('Attendees', $guestListLabel, $this->owner->Attendees(), GuestListGridFieldConfig::create())
            );
        }

        // Create WaitingList tab
        if ($this->owner->WaitingList()->exists()) {
            $waitingListLabel = _t(__CLASS__ . '.WaitingList', 'WaitingList');
            $fields->addFieldToTab(
                "Root.$waitingListLabel",
                GridField::create('WaitingList', $waitingListLabel, $this->owner->WaitingList(), WaitingListGridFieldConfig::create())
            );
        }

        // Create Fields tab
        $extraFieldsLabel = _t(__CLASS__ . '.ExtraFields', 'Attendee fields');
        $fields->addFieldToTab(
            "Root.$extraFieldsLabel",
            GridField::create('ExtraFields', $extraFieldsLabel, $this->owner->Fields(), UserFieldsGridFieldConfig::create([
                'ClassName' => function($record, $column, $grid) {
                    return new DropdownField($column, $column, UserField::availableFields());
                },
                'Title' => function($record, $column, $grid) {
                    return new TextField($column);
                },
                'RequiredNice' => 'Required field'
            ]))
        );

        $this->owner->extend('updateTicketExtensionFields', $fields);
    }

    /**
     * Trigger actions after write
     */
    public function onAfterWrite()
    {
        $this->createDefaultFields();
        parent::onAfterWrite();
    }

    /**
     * Creates and sets up the default fields
     */
    public function createDefaultFields()
    {
        $fields = Attendee::config()->get('default_fields');
        if (!$this->owner->Fields()->exists()) {
            $sort = 0;
            foreach ($fields as $fieldName => $config) {
                $field = UserField::createDefaultField($fieldName, $config);
                $this->owner->Fields()->add($field, ['Sort' => $sort]);
                $sort++;
            }
        }
    }

    /**
     * Extend the page actions with an start check in action
     *
     * @param FieldList $actions
     */
    public function updateCMSActions(FieldList $actions)
    {
        if ($this->owner->Attendees()->exists()) {
            // $link = $this->owner->Link('checkin');
            $link = CheckInController::singleton()->Link("event/{$this->owner->ID}");
            $label = _t(__CLASS__ . '.StartCheckIn', 'Start check in');
            $action = LiteralField::create(
                'StartCheckIn',
                "<a href='$link' target='_blank' class='no-ajax btn btn-outline-secondary font-icon-checklist'>$label</a>"
            );

            $actions->push($action);
        }
    }

    /**
     * Get the guest list status used in the summary fields
     */
    public function getGuestListStatus()
    {
        $guests = $this->owner->getGuestList()->count();
        $capacity = $this->owner->Capacity;
        return "$guests/$capacity";
    }

    /**
     * Get the leftover capacity
     *
     * @return int
     */
    public function getAvailability()
    {
        return $this->owner->Capacity - $this->owner->getGuestList()->count();
    }

    /**
     * Check if the tickets are still available
     *
     * @return bool
     */
    public function getTicketsAvailable()
    {
        return $this->owner->getAvailability() > 0;
    }

    /**
     * Check if the tickets are sold out
     * @return bool
     */
    public function getTicketsSoldOut()
    {
        return $this->owner->getAvailability() <= 0;
    }

    /**
     * get The sale start date
     *
     * @return DBDate|DBField|null
     * @throws Exception
     */
    public function getTicketSaleStartDate()
    {
        $saleStart = null;
        if (($tickets = $this->owner->Tickets())) {
            /** @var Ticket $ticket */
            foreach ($tickets as $ticket) {
                if (($date = $ticket->getAvailableFrom()) && strtotime($date) < strtotime($saleStart) || $saleStart === null) {
                    $saleStart = $date;
                }
            }
        }

        return $saleStart;
    }

    /**
     * Check if the event is expired, either by unavailable tickets or because the date has passed
     *
     * @return bool
     * @throws Exception
     */
    public function getEventExpired()
    {
        $expired = false;
        if (($tickets = $this->owner->Tickets()) && $expired = $tickets->exists()) {
            /** @var Ticket $ticket */
            foreach ($tickets as $ticket) {
                $expired = (!$ticket->validateDate() && $expired);
            }
        }

        return $expired;
    }

    /**
     * Check if the ticket sale is still pending
     *
     * @return bool
     */
    public function getTicketSalePending()
    {
        return time() < strtotime($this->owner->getTicketSaleStartDate());
    }

    /**
     * Get only the attendees who are certain to attend
     * Also includes attendees without any reservation, these are manually added
     *
     * @return DataList
     */
    public function getGuestList()
    {
        $reservation = Reservation::singleton()->baseTable();
        $attendee = Attendee::singleton()->baseTable();
        return Attendee::get()
            ->leftJoin($reservation, "`$attendee`.`ReservationID` = `$reservation`.`ID`")
            ->filter(array(
                'TicketPageID' => $this->owner->ID
            ))
            ->filterAny(array(
                'ReservationID' => 0,
                'Status' => Reservation::STATUS_PAID
            ));
    }

    /**
     * Get the checked in count for display in templates
     *
     * @return string
     */
    public function getCheckedInCount()
    {
        $attendees = $this->getGuestList();
        $checkedIn = $attendees->filter('CheckedIn', true)->count();
        return "($checkedIn/{$attendees->count()})";
    }

    /**
     * Get the success message
     *
     * @return mixed|string
     */
    public function getSuccessContent()
    {
        if (!empty($this->owner->SuccessMessage)) {
            return $this->owner->dbObject('SuccessMessage');
        } else {
            return SiteConfig::current_site_config()->dbObject('SuccessMessage');
        }
    }

    /**
     * Get the mail message
     *
     * @return mixed|string
     */
    public function getMailContent()
    {
        if (!empty($this->owner->SuccessMessageMail)) {
            return $this->owner->dbObject('SuccessMessageMail');
        } else {
            return SiteConfig::current_site_config()->dbObject('SuccessMessageMail');
        }
    }

    public function getTicketContent()
    {
        if (!empty($this->owner->PrintedTicketContent)) {
            return $this->owner->dbObject('PrintedTicketContent');
        } else {
            return SiteConfig::current_site_config()->dbObject('PrintedTicketContent');
        }
    }

    /**
     * Check if the method 'getTicketEventTitle' has been set and retrieve the event name.
     * This is used in ticket emails
     *
     * @return string
     * @throws Exception
     */
    public function getEventTitle()
    {
        throw new Exception("You should create a method 'getEventTitle' on {$this->owner->ClassName}");
    }

    public function getEventStartDate()
    {
        throw new Exception("You should create a method 'getEventStartDate' on {$this->owner->ClassName}");
    }

    public function getEventAddress()
    {
        throw new Exception("You should create a method 'getEventAddress' on {$this->owner->ClassName}");
    }
}
