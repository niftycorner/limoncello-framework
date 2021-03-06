<?php declare (strict_types=1);

namespace Limoncello\Flute\Types;

/**
 * @package App
 */
class LineStringType extends \Brick\Geo\Doctrine\Types\PointType
{
    /**
     * Type name
     */
    const NAME = 'limoncelloLineString';
}
