<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something a person did cannot be done (out of stock, order already shipped, balance too low...).
 * The message is written for people and shown to them as it is.
 */
class MarketplaceException extends RuntimeException {}
