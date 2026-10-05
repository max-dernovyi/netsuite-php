<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Exception;

/**
 * The operation has no REST implementation and cannot fall back to SOAP.
 */
final class NotSupportedOnRestException extends \RuntimeException
{
}
