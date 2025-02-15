<?php

/**
 * Parses a list of options.
 *
 * @package    SqlParser
 * @subpackage Components
 */
namespace SqlParser\Components;

use SqlParser\Component;
use SqlParser\Parser;
use SqlParser\Token;
use SqlParser\TokensList;

/**
 * Parses a list of options.
 *
 * @category   Components
 * @package    SqlParser
 * @subpackage Components
 * @author     Dan Ungureanu <udan1107@gmail.com>
 * @license    http://opensource.org/licenses/GPL-2.0 GNU Public License
 */
class OptionsArray extends Component
{

    /**
     * ArrayObj of selected options.
     *
     * @var array
     */
    public $options = array();

    /**
     * Constructor.
     *
     * @param array $options The array of options. Options that have a value
     *                       must be an array with two keys 'name' and 'value'.
     */
    public function __construct(array $options = array())
    {
        $this->options = $options;
    }

    /**
     * @param Parser     $parser  The parser that serves as context.
     * @param TokensList $list    The list of tokens that are being parsed.
     * @param array      $options Parameters for parsing.
     *
     * @return OptionsArray
     */
    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new OptionsArray();

         /**
          * The ID that will be assigned to duplicate options.
          * @var int $lastAssignedId
          */
        $lastAssignedId = count($options) + 1;

        /**
         * The option that was processed last time.
         * @var array $lastOption
         */
        $lastOption = null;

        /**
         * The index of the option that was processed last time.
         * @var int $lastOptionId
         */
        $lastOptionId = 0;

        /**
         * Counts brackets.
         * @var int $brackets
         */
        $brackets = 0;

        /**
         * The state of the parser.
         *
         * Below are the states of the parser.
         *
         *      0 ---------------------[ option ]----------------------> 1
         *
         *      1 -------------------[ = (optional) ]------------------> 2
         *
         *      2 ----------------------[ value ]----------------------> 0
         *
         * @var int
         */
        $state = 0;

        for (; $list->idx < $list->count; ++$list->idx) {
            /**
             * Token parsed at this moment.
             * @var Token $token
             */
            $token = $list->tokens[$list->idx];

            // End of statement.
            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            // Skipping comments.
            if ($token->type === Token::TYPE_COMMENT) {
                continue;
            }

            // Skipping whitespace if not parsing value.
            if (($token->type === Token::TYPE_WHITESPACE) && ($brackets === 0)) {
                continue;
            }

            if ($lastOption === null) {
                $upper = strtoupper($token->token);
                if (isset($options[$upper])) {
                    $lastOption = $options[$upper];
                    $lastOptionId = is_array($lastOption) ?
                        $lastOption[0] : $lastOption;
                    $state = 0;

                    // Checking for option conflicts.
                    // For example, in `SELECT` statements the keywords `ALL`
                    // and `DISTINCT` conflict and if used together, they
                    // produce an invalid query.
                    //
                    // Usually, tokens can be identified in the array by the
                    // option ID, but if conflicts occur, a generated option ID
                    // is used.
                    //
                    // The first pseudo duplicate ID is the maximum value of the
                    // real options (e.g.  if there are 5 options, the first
                    // fake ID is 6).
                    if (isset($ret->options[$lastOptionId])) {
                        $parser->error('This option conflicts with \'' . $ret->options[$lastOptionId] . '\'.', $token);
                        $lastOptionId = $lastAssignedId++;
                    }
                } else {
                    // There is no option to be processed.
                    break;
                }
            }

            if ($state === 0) {
                if (!is_array($lastOption)) {
                    // This is a just keyword option without any value.
                    // This is the beginning and the end of it.
                    $ret->options[$lastOptionId] = $token->value;
                    $lastOption = null;
                    $state = 0;
                } elseif (($lastOption[1] === 'var') || ($lastOption[1] === 'var=')) {
                    // This is a keyword that is followed by a value.
                    // This is only the beginning. The value is parsed in state
                    // 1 and 2. State 1 is used to skip the first equals sign
                    // and state 2 to parse the actual value.
                    $ret->options[$lastOptionId] = array(
                        // @var string The name of the option.
                        'name' => $token->value,
                        // @var bool Whether it contains an equal sign.
                        //           This is used by the builder to rebuild it.
                        'equal' => $lastOption[1] === 'var=',
                        // @var string Raw value.
                        'value' => '',
                        // @var string Processed value.
                        'value_' => '',
                    );
                    $state = 1;
                } elseif ($lastOption[1] === 'expr') {
                    // This is a keyword that is followed by an expression.
                    // The expression is used by the specialized parser.

                    // Skipping this option in order to parse the expression.
                    ++$list->idx;
                    $ret->options[$lastOptionId] = array(
                        // @var string The name of the option.
                        'name' => $token->value,
                        // @var Expression The parsed expression.
                        'value' => null,
                    );
                    $state = 1;
                }
            } elseif ($state === 1) {
                $state = 2;
                if ($token->value === '=') {
                    $ret->options[$lastOptionId]['equal'] = true;
                    continue;
                }
            }

            // This is outside the `elseif` group above because the change might
            // change this iteration.
            if ($state === 2) {
                if ($lastOption[1] === 'expr') {
                    $ret->options[$lastOptionId]['value'] = Expression::parse(
                        $parser,
                        $list,
                        empty($lastOption[2]) ? array() : $lastOption[2]
                    );
                    $lastOption = null;
                    $state = 0;
                } else {
                    if ($token->token === '(') {
                        ++$brackets;
                    } elseif ($token->token === ')') {
                        --$brackets;
                    }

                    // Raw value.
                    $ret->options[$lastOptionId]['value'] .= $token->token;

                    // Processed value.
                    $ret->options[$lastOptionId]['value_'] .= $token->value;

                    // Checking if we finished parsing.
                    if ($brackets === 0) {
                        $lastOption = null;
                    }
                }
            }
        }

        ksort($ret->options);

        --$list->idx;
        return $ret;
    }

    /**
     * @param OptionsArray $component The component to be built.
     *
     * @return string
     */
    public static function build($component)
    {
        if ((empty($component)) || (!is_array($component->options))) {
            return '';
        }
        $options = array();
        foreach ($component->options as $option) {
            if (!is_array($option)) {
                $options[] = $option;
            } else {
                $options[] = $option['name']
                    . (!empty($option['equal']) ? '=' : ' ')
                    . ((string) $option['value']);
                // If `$option['value']` happens to be a component, the magic
                // method will build it automatically.
            }
        }
        return implode(' ', $options);
    }

    /**
     * Checks if it has the specified option and returns it value or true.
     *
     * @param string $key The key to be checked.
     *
     * @return mixed
     */
    public function has($key)
    {
        foreach ($this->options as $option) {
            if ($key === $option) {
                return true;
            } elseif ((is_array($option)) && ($key === $option['name'])) {
                return $option['value'];
            }
        }
        return false;
    }

    /**
     * Removes the option from the array.
     *
     * @param string $key The key to be removed.
     *
     * @return bool Whether the key was found and deleted or not.
     */
    public function remove($key)
    {
        foreach ($this->options as $idx => $option) {
            if (($key === $option)
                || ((is_array($option)) && ($key === $option['name']))
            ) {
                unset($this->options[$idx]);
                return true;
            }
        }
        return false;
    }

    /**
     * Merges the specified options with these ones. Values with same ID will be
     * replaced.
     *
     * @param array|OptionsArray $options The options to be merged.
     *
     * @return void
     */
    public function merge($options)
    {
        if (is_array($options)) {
            $this->options = array_merge_recursive($this->options, $options);
        } elseif ($options instanceof OptionsArray) {
            $this->options = array_merge_recursive($this->options, $options->options);
        }
    }
}
