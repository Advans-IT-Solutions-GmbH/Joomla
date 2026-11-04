<?php

declare (strict_types=1);
namespace Advans\Component\Appointments\Vendor\Sabre\CalDAV\Xml\Request;
\defined('_JEXEC') or die;

use Advans\Component\Appointments\Vendor\Sabre\CalDAV\Plugin;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\BadRequest;
use Advans\Component\Appointments\Vendor\Sabre\Xml\Reader;
use Advans\Component\Appointments\Vendor\Sabre\Xml\XmlDeserializable;
/**
 * CalendarQueryReport request parser.
 *
 * This class parses the {urn:ietf:params:xml:ns:caldav}calendar-query
 * REPORT, as defined in:
 *
 * https://tools.ietf.org/html/rfc4791#section-7.8
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class CalendarQueryReport implements XmlDeserializable
{
    /**
     * An array with requested properties.
     *
     * @var array
     */
    public $properties;
    /**
     * List of property/component filters.
     *
     * @var array
     */
    public $filters;
    /**
     * If the calendar data must be expanded, this will contain an array with 2
     * elements: start and end.
     *
     * Each may be a DateTime or null.
     *
     * @var array|null
     */
    public $expand = null;
    /**
     * The mimetype of the content that should be returned. Usually
     * text/calendar.
     *
     * @var string
     */
    public $contentType = null;
    /**
     * The version of calendar-data that should be returned. Usually '2.0',
     * referring to iCalendar 2.0.
     *
     * @var string
     */
    public $version = null;
    /**
     * The deserialize method is called during xml parsing.
     *
     * This method is called statically, this is because in theory this method
     * may be used as a type of constructor, or factory method.
     *
     * Often you want to return an instance of the current class, but you are
     * free to return other data as well.
     *
     * You are responsible for advancing the reader to the next element. Not
     * doing anything will result in a never-ending loop.
     *
     * If you just want to skip parsing for this element altogether, you can
     * just call $reader->next();
     *
     * $reader->parseInnerTree() will parse the entire sub-tree, and advance to
     * the next element.
     *
     * @return mixed
     */
    public static function xmlDeserialize(Reader $reader)
    {
        $elems = $reader->parseInnerTree(['{urn:ietf:params:xml:ns:caldav}comp-filter' => \Advans\Component\Appointments\Vendor\Sabre\CalDAV\Xml\Filter\CompFilter::class, '{urn:ietf:params:xml:ns:caldav}prop-filter' => \Advans\Component\Appointments\Vendor\Sabre\CalDAV\Xml\Filter\PropFilter::class, '{urn:ietf:params:xml:ns:caldav}param-filter' => \Advans\Component\Appointments\Vendor\Sabre\CalDAV\Xml\Filter\ParamFilter::class, '{urn:ietf:params:xml:ns:caldav}calendar-data' => \Advans\Component\Appointments\Vendor\Sabre\CalDAV\Xml\Filter\CalendarData::class, '{DAV:}prop' => \Advans\Component\Appointments\Vendor\Sabre\Xml\Element\KeyValue::class]);
        $newProps = ['filters' => null, 'properties' => []];
        if (!is_array($elems)) {
            $elems = [];
        }
        foreach ($elems as $elem) {
            switch ($elem['name']) {
                case '{DAV:}prop':
                    $newProps['properties'] = array_keys($elem['value']);
                    if (isset($elem['value']['{' . Plugin::NS_CALDAV . '}calendar-data'])) {
                        $newProps += $elem['value']['{' . Plugin::NS_CALDAV . '}calendar-data'];
                    }
                    break;
                case '{' . Plugin::NS_CALDAV . '}filter':
                    foreach ($elem['value'] as $subElem) {
                        if ($subElem['name'] === '{' . Plugin::NS_CALDAV . '}comp-filter') {
                            if (!is_null($newProps['filters'])) {
                                throw new BadRequest('Only one top-level comp-filter may be defined');
                            }
                            $newProps['filters'] = $subElem['value'];
                        }
                    }
                    break;
            }
        }
        if (is_null($newProps['filters'])) {
            throw new BadRequest('The {' . Plugin::NS_CALDAV . '}filter element is required for this request');
        }
        $obj = new self();
        foreach ($newProps as $key => $value) {
            $obj->{$key} = $value;
        }
        return $obj;
    }
}
