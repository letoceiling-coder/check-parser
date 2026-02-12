<?php

namespace App\Exceptions;

use Exception;

class NoActiveRaffleException extends Exception
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Сейчас нет активного розыгрыша.');
    }
}
