<?php

namespace App\Exceptions;

use DomainException;

/**
 * Se intentó un cambio de estado que no está en la tabla de transiciones.
 */
class TransicionInvalida extends DomainException {}
