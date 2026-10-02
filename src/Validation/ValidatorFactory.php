<?php

declare(strict_types=1);

namespace PluginKernel\Validation;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ValidatorFactory
{
    private static ?ValidatorInterface $validator = null;

    public static function build(): ValidatorInterface
    {
        if (null === self::$validator) {
            try {
                self::$validator = Validation::createValidatorBuilder()
                                             ->enableAttributeMapping()
                                             ->getValidator();
            } catch (\Throwable) {
                self::$validator = Validation::createValidatorBuilder()
                                             ->getValidator();
            }
        }

        return self::$validator;
    }
}
