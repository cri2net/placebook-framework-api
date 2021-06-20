<?php

namespace Placebook\Framework\Core\Schema;

use Youshido\GraphQL\Type\Scalar\StringType;

/**
 * Type: E-mail
 */
class EmailType extends StringType
{
    public function getName()
    {
        return 'Email';
    }

    public function isValidValue($value)
    {
        return is_null($value) || filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    public function getDescription()
    {
        return 'The `Email` type represents valid E-mail address';
    }
}
