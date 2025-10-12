<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */
declare(strict_types=1);

namespace Lemric\BatchRequest\Parser;

/**
 * Token types for batch request parsing.
 */
enum TokenType: string
{
    case ARRAY_END = 'array_end';

    case ARRAY_START = 'array_start';

    case BOOLEAN = 'boolean';

    case KEY = 'key';

    case NULL = 'null';

    case NUMBER = 'number';

    case OBJECT_END = 'object_end';

    case OBJECT_START = 'object_start';

    case STRING = 'string';

    case VALUE = 'value';
}
