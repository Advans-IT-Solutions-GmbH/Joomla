<?php

declare (strict_types=1);
namespace Advans\Component\Appointments\Vendor\Sabre\Event;
\defined('_JEXEC') or die;

/**
 * Emitter object.
 *
 * Instantiate this class, or subclass it for easily creating event emitters.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Emitter implements EmitterInterface
{
    use EmitterTrait;
}
