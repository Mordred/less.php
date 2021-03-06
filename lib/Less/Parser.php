<?php

namespace Less;

class Parser {

    /**
     * @var string
     */
    private $input;

    /**
     * @var string
     */
    private $current;

    /**
     * @var int
     */
    private $pos;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var string
     */
    private $css;

    /**
     * @var length
     */
    private $length = 0;

    /**
     *
     */
    static public $version = '1.3.0';

    /**
     * @var \Less\Environment
     */
    private $env;

	public $imports = array();

    /**
     * @param Environment|null $env
     */
    public function __construct(\Less\Environment $env = null)
    {
        $this->env = $env ?: new \Less\Environment();
        $this->css = '';
        $this->pos = 0;
    }

    /**
     * Get the current parser environment
     *
     * @return Environment
     */
    public function getEnvironment()
    {
        return $this->env;
    }

    /**
     * Set the current parser environment
     *
     * @param \Less\Envronment $env
     * @return void
     */
    public function setEnvironment(\Less\Envronment $env)
    {
        $this->env = $env;
    }

    /**
     * Get the current css buffer
     *
     * @return string
     */
    public function getCss()
    {
        return $this->css;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->css;
    }

    /**
     * Clear the css buffer
     *
     * @return void
     */
    public function clearCss()
    {
        $this->css = '';
    }

    /**
     * Parse a Less string into css
     *
     * @param string $str The string to convert
     * @param bool $returnRoot Indicates whether the return value should be a css string a root node
     * @return \Less\Node\Ruleset|\Less\Parser
     */
    public function parse($str, $returnRoot = false, $filename = NULL)
    {
        if ($filename) {
            $this->filename = $filename;
            $this->path = dirname($filename);
        }
        $this->pos = 0;
        $this->input = preg_replace('/\r\n/', "\n", $str);
        $this->length = strlen($this->input);
        $root = new \Less\Node\Ruleset(false, $this->match('parsePrimary'));
        $root->root = true;

        if ($returnRoot) {
            return $root;
        } else {
            $this->css .= $root->compile($this->env)->toCSS(array(), $this->env);

            return $this;
        }
    }

    /**
     * Parse a Less string from a given file
     *
     * @throws Exception\ParserException
     * @param $filename The file to parse
     * @param bool $returnRoot Indicates whether the return value should be a css string a root node
     * @return \Less\Node\Ruleset|\Less\Parser
     */
    public function parseFile($filename, $returnRoot = false)
    {
        if ( ! is_file($filename)) {
            throw new \Less\Exception\ParserException(sprintf('File `%s` not found.', $filename));
        }

        $this->path = pathinfo($filename, PATHINFO_DIRNAME);
        $this->filename = $filename;

        return $this->parse(file_get_contents($filename), $returnRoot);
    }

    /**
     * Update $this->current to reflect $this->input from the $this->pos
     *
     * @return void
     */
    public function sync()
    {
        $this->current = substr($this->input, $this->pos);
    }

    /**
     * Parse from a token, regexp or string, and move forward if match
     *
     * @param string $tok
     * @return null|bool|object
     */
    public function match($tok)
    {
        $match = null;
        if (is_callable(array($this, $tok))) {
            // Non-terminal, match using a function call
            return $this->$tok();
        } else if (!isset($tok[1])) {
            // Match a single character in the input,
            $match = isset($this->input[$this->pos]) && $this->input[$this->pos] === $tok ? $tok : null;
            $length = 1;
            $this->sync();
        } else {
            // Match a regexp from the current start point
            $this->sync();
            if (preg_match($tok, $this->current, $match)) {
                $length = strlen($match[0]);
            } else {
                return null;
            }
        }

        // The match is confirmed, add the match length to `this::pos`,
        // and consume any extra white-space characters (' ' || '\n')
        // which come after that. The reason for this is that LeSS's
        // grammar is mostly white-space insensitive.
        //
        if ($match) {

            $this->pos += $length;
            while ($this->pos < $this->length) {
                //$c = ord($this->input[$this->pos]);
                if ( ! ($this->input[$this->pos] === "\x20"
                    || $this->input[$this->pos] === "\x0A"
                    || $this->input[$this->pos] === "\x09")) {
                        break;
                }
                $this->pos++;

            }
            $this->sync();
            if (is_string($match)) {
                return $match;
            } else {
                return count($match) === 1 ? $match[0] : $match;
            }
        }
    }

    /**
     * Same as match(), but don't change the state of the parser,
     * just return the match.
     *
     * @param $tok
     * @param int $offset
     * @return bool
     */
    public function peek($tok, $offset = 0)
    {
        if (!isset($tok[1])) {
            return ($this->length > ($this->pos + $offset)) && $this->input[$this->pos + $offset] === $tok;
        } else {
            if (preg_match($tok, $this->current, $matches)) {
                return true;
            } else {
                return false;
            }
        }
    }

	public function expect($tok, $msg = NULL) {
		$result = $this->match($tok);
		if (!$result) {
			throw new \Less\Exception\ParserException(
				$msg === NULL
					? "Expected '" . $tok . "' got '" . $this->input[$this->pos] . "'"
					: $msg
			);
		} else {
			return $result;
		}
	}

    //
    // Here in, the parsing rules/functions
    //
    // The basic structure of the syntax tree generated is as follows:
    //
    //   Ruleset ->  Rule -> Value -> Expression -> Entity
    //
    // Here's some LESS code:
    //
    //    .class {
    //      color: #fff;
    //      border: 1px solid #000;
    //      width: @w + 4px;
    //      > .child {...}
    //    }
    //
    // And here's what the parse tree might look like:
    //
    //     Ruleset (Selector '.class', [
    //         Rule ("color",  Value ([Expression [Color #fff]]))
    //         Rule ("border", Value ([Expression [Dimension 1px][Keyword "solid"][Color #000]]))
    //         Rule ("width",  Value ([Expression [Operation "+" [Variable "@w"][Dimension 4px]]]))
    //         Ruleset (Selector [Element '>', '.child'], [...])
    //     ])
    //
    //  In general, most rules will try to parse a token with the `$()` function, and if the return
    //  value is truly, will return a new node, of the relevant type. Sometimes, we need to check
    //  first, before parsing, that's when we use `peek()`.
    //

    //
    // The `primary` rule is the *entry* and *exit* point of the parser.
    // The rules here can appear at any level of the parse tree.
    //
    // The recursive nature of the grammar is an interplay between the `block`
    // rule, which represents `{ ... }`, the `ruleset` rule, and this `primary` rule,
    // as represented by this simplified grammar:
    //
    //     primary  →  (ruleset | rule)+
    //     ruleset  →  selector+ block
    //     block    →  '{' primary '}'
    //
    // Only at one point is the primary rule not called from the
    // block rule: at the root level.
    //
    private function parsePrimary()
    {
        $root = array();
        while (($node = $this->match('parseMixinDefinition') ?:
                        $this->match('parseRule') ?:
                        $this->match('parseRuleset') ?:
                        $this->match('parseMixinCall') ?:
                        $this->match('parseComment') ?:
                        $this->match('parseDirective')) ?:
                        $this->match('/^[\s\n]+/')
        ) {
            if ($node) {
                $root[] = $node;
            }
        }

        return $root;
    }

    // We create a Comment node for CSS comments `/* */`,
    // but keep the LeSS comments `//` silent, by just skipping
    // over them.
    private function parseComment()
    {
        if ( ! $this->peek('/')) {
            return;
        }

        if ($this->peek('/', 1)) {
            return new \Less\Node\Comment($this->match('/^\/\/.*/'), true);
        } else {
            if ($comment = $this->match('#/\*.*?\*/\n?#s')) {
                return new \Less\Node\Comment($comment, false);
            }
        }
    }

    //
    // A string, which supports escaping " and '
    //
    //     "milky way" 'he\'s the one!'
    //
    private function parseEntitiesQuoted() {
		$j = 0;
		$e = false;

        if ($this->peek('~')) {
			$j++;
            $e = true; // Escaped strings
        }

        if ( ! $this->peek('"', $j) && ! $this->peek("'", $j)) {
            return;
        }

        if ($e) {
            $this->match('~');
        }

        if ($str = $this->match('/^"((?:[^"\\\\\r\n]|\\\\.)*)"|\'((?:[^\'\\\\\r\n]|\\\\.)*)\'/')) {
			$result = $str[0][0] == '"' ? $str[1] : $str[2];
			return new \Less\Node\Quoted($str[0], $result, $e);
        }

        return;
    }

    //
    // A catch-all word, such as:
    //
    //     black border-collapse
    //
    private function parseEntitiesKeyword()
    {
        if ($k = $this->match('/^[_A-Za-z-][_A-Za-z0-9-]*/')) {
			if (\Less\Colors::hasOwnProperty($k))
				// detected named color
				return new \Less\Node\Color(substr(\Less\Colors::color($k), 1));
			else
				return new \Less\Node\Keyword($k);
        }

        return;
    }

    //
    // A function call
    //
    //     rgb(255, 0, 255)
    //
    // We also try to catch IE's `alpha()`, but let the `alpha` parser
    // deal with the details.
    //
    // The arguments are parsed with the `entities.arguments` parser.
    //
    private function parseEntitiesCall()
    {
        $index = $this->pos;
        if ( ! preg_match('/^([\w-]+|%|progid:[\w\.]+)\(/', $this->current, $name)) {
            return;
        }
        $name = strtolower($name[1]);

        if ($name === 'url') {
            return null;
        } else {
            $this->pos += strlen($name);
        }

        if ($name === 'alpha') {
            return $this->match('parseAlpha');
        }

        $this->match('('); // Parse the '(' and consume whitespace.
        $args = $this->match('parseEntitiesArguments');
        if ( ! $this->match(')')) {
            return;
        }
        if ($name) {
            return new \Less\Node\Call($name, $args, $index, $this->filename);
        }
    }

    /**
     * Parse a list of arguments
     *
     * @return array
     */
    private function parseEntitiesArguments()
    {
        $args = array();
        while ($arg = $this->match('parseEntitiesAssigment') ?: $this->match('parseExpression')) {
            $args[] = $arg;
            if (! $this->match(',')) {
                break;
            }
        }
        return $args;
    }

    private function parseEntitiesLiteral()
    {
        return $this->match('parseEntitiesDimension') ?:
               $this->match('parseEntitiesColor') ?:
               $this->match('parseEntitiesQuoted');
    }

	// Assignments are argument entities for calls.
	// They are present in ie filter properties as shown below.
	//
	//     filter: progid:DXImageTransform.Microsoft.Alpha( *opacity=50* )
	//
	private function parseEntitiesAssigment() {
		if (($key = $this->match('/^\w+(?=\s?=)/i')) && $this->match('=') && ($value = $this->match('parseEntity'))) {
			return new \Less\Node\Assigment($key, $value);
		}
	}

    //
    // Parse url() tokens
    //
    // We use a specific rule for urls, because they don't really behave like
    // standard function calls. The difference is that the argument doesn't have
    // to be enclosed within a string, so it can't be parsed as an Expression.
    //
    private function parseEntitiesUrl()
    {
        if (! $this->peek('u') || ! $this->match('/^url\(/')) {
            return;
        }

        $value = $this->match('parseEntitiesQuoted') ?:
                 $this->match('parseEntitiesVariable') ?:
                 $this->match('parseEntitiesDataURI') ?:
                 $this->match('/^[-\w%@$\/.&=:;#+?~]+/') ?: '';

		$this->expect(')');

        return new \Less\Node\Url((isset($value->value) || isset($value->data) || $value instanceof \Less\Node\Variable)
                            ? $value : new \Less\Node\Anonymous($value), '');
    }

    private function parseEntitiesDataURI()
    {
        if ($this->match('/^data:/')) {
            $obj = new \stdClass();
            $obj->mime    = $this->match('/^[^\/]+\/[^,;)]+/')     ?: '';
            $obj->charset = $this->match('/^;\s*charset=[^,;)]+/') ?: '';
            $obj->base64  = $this->match('/^;\s*base64/')          ?: '';
            $obj->data    = $this->match('/^,\s*[^)]+/');
            if ($obj->data) {
                return $obj;
            }
        }
    }

    //
    // A Variable entity, such as `@fink`, in
    //
    //     width: @fink + 2px
    //
    // We use a different parser for variable definitions,
    // see `parsers.variable`.
    //
    private function parseEntitiesVariable()
    {
        $index = $this->pos;
        if ($this->peek('@') && ($name = $this->match('/^@@?[\w-]+/'))) {
            return new \Less\Node\Variable($name, $index, $this->filename);
        }
    }

    //
    // A Hexadecimal color
    //
    //     #4F3C2F
    //
    // `rgb` and `hsl` colors are parsed through the `entities.call` parser.
    //
    private function parseEntitiesColor()
    {
        if ($this->peek('#') && ($rgb = $this->match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})/'))) {
            return new \Less\Node\Color($rgb[1]);
        }
    }

    //
    // A Dimension, that is, a number and a unit
    //
    //     0.5em 95%
    //
    private function parseEntitiesDimension()
    {
        $c = ord($this->input[$this->pos]);
        if (($c > 57 || $c < 45) || $c === 47) {
    return;
        }

        if ($value = $this->match('/^(-?\d*\.?\d+)(px|%|em|rem|pc|ex|in|deg|s|ms|pt|cm|mm|rad|grad|turn)?/')) {
            return new \Less\Node\Dimension($value[1], isset($value[2]) ? $value[2] : null);
        }
    }

    //
    // JavaScript code to be evaluated
    //
    //     `window.location.href`
    //
    private function parseEntitiesJavascript()
    {
        $e = false;
        if ($this->peek('~')) {
            $e = true;
        }
        if (! $this->peek('`', $e)) {
            return;
        }
        if ($e) {
            $this->match('~');
        }
        if ($str = $this->match('/^`([^`]*)`/')) {
            return new \Less\Node\Javascript($str[1], $this->pos, $e);
        }
    }


    //
    // The variable part of a variable definition. Used in the `rule` parser
    //
    //     @fink:
    //
    private function parseVariable()
    {
        if ($this->peek('@') && ($name = $this->match('/^(@[\w-]+)\s*:/'))) {
            return $name[1];
        }
        return;
    }

    //
    // A font size/line-height shorthand
    //
    //     small/12px
    //
    // We need to peek first, or we'll match on keywords and dimensions
    //
    private function parseShorthand()
    {
        if (! $this->peek('/^[@\w.%-]+\/[@\w.-]+/')) {
            return;
        }
        if (($a = $this->match('parseEntity')) && $this->match('/') && ($b = $this->match('parseEntity'))) {
            return new \Less\Node\Shorthand($a, $b);
        }
    }

    //
    // A Mixin call, with an optional argument list
    //
    //     #mixins > .square(#fff);
    //     .rounded(4px, black);
    //     .button;
    //
    // The `while` loop is there because mixins can be
    // namespaced, but we only support the child and descendant
    // selector for now.
    //
    private function parseMixinCall()
    {
        $elements = array();
        $args = array();
        $c = null;
        $index = $this->pos;
		$important = false;

        if ( ! $this->peek('.') && ! $this->peek('#')) {
            return;
        }

        while ($e = $this->match('/^[#.](?:[\w-]|\\\(?:[a-fA-F0-9]{1,6} ?|[^a-fA-F0-9]))+/')) {
            $elements[] = new \Less\Node\Element($c, $e, $index);
            $c = $this->match('>');
        }

        if ($this->match('(')) {
            $args = $this->match('parseEntitiesArguments');
            $this->match(')');
        }

		if ($this->match('parseImportant'))
			$important = true;

        if (count($elements) > 0 && ($this->match(';') || $this->peek('}'))) {
            return new \Less\Node\Mixin\Call($elements, $args, $index, $this->filename, $important);
        }
    }

    //
    // A Mixin definition, with a list of parameters
    //
    //     .rounded (@radius: 2px, @color) {
    //        ...
    //     }
    //
    // Until we have a finer grained state-machine, we have to
    // do a look-ahead, to make sure we don't have a mixin call.
    // See the `rule` function for more information.
    //
    // We start by matching `.rounded (`, and then proceed on to
    // the argument list, which has optional default values.
    // We store the parameters in `params`, with a `value` key,
    // if there is a value, such as in the case of `@radius`.
    //
    // Once we've got our params list, and a closing `)`, we parse
    // the `{...}` block.
    //
    private function parseMixinDefinition()
    {
        $params = array();
		$variadic = false;
		$cond = null;

        if ((! $this->peek('.') && ! $this->peek('#')) || $this->peek('/^[^{]*(;|})/')) {
            return;
        }

        $start = $this->pos;

        if ($match = $this->match('/^([#.](?:[\w-]|\\\(?:[a-fA-F0-9]{1,6} ?|[^a-fA-F0-9]))+)\s*\(/')) {
            $name = $match[1];

			do {
				if ($this->peek('.') && $this->match("/^\.{3}/")) {
					$variadic = true;
					break;
				} elseif ($param = $this->match('parseEntitiesVariable') ?:
                            $this->match('parseEntitiesLiteral') ?:
                            $this->match('parseEntitiesKeyword')) {
					// Variable
					if ($param instanceof \Less\Node\Variable) {
	                    if ($this->match(':')) {
							$value = $this->expect('parseExpression', 'Expected expression');
                            $params[] = array('name' => $param->name, 'value' => $value);
						} elseif ($this->match("/^\.{3}/")) {
							$params[] = array('name' => $param->name, 'variadic' => true);
							$variadic = true;
							break;
						} else {
	                        $params[] = array('name' => $param->name);
						}
                    } else {
                        $params[] = array('value' => $param);
                    }
				} else {
					break;
				}
			} while ($this->match(','));

			$this->expect(')');

			if ($this->match('/^when/')) { // Guard
				$cond = $this->expect('parseConditions', 'Expected conditions');
			}

            $ruleset = $this->match('parseBlock');

            if (is_array($ruleset)) {
                return new \Less\Node\Mixin\Definition($name, $params, $ruleset, $cond, $variadic);
            } else {
				$this->pos = $start;
				$this->sync();
			}
        }
    }

    //
    // Entities are the smallest recognized token,
    // and can be found inside a rule's value.
    //
    private function parseEntity()
    {

        return $this->match('parseEntitiesLiteral') ?:
               $this->match('parseEntitiesVariable') ?:
               $this->match('parseEntitiesUrl') ?:
               $this->match('parseEntitiesCall') ?:
               $this->match('parseEntitiesKeyword') ?:
               $this->match('parseEntitiesJavascript') ?:
               $this->match('parseComment');
    }

    //
    // A Rule terminator. Note that we use `peek()` to check for '}',
    // because the `block` rule will be expecting it, but we still need to make sure
    // it's there, if ';' was ommitted.
    //
    private function parseEnd()
    {
        return $this->match(';') ?: $this->peek('}');
    }

    //
    // IE's alpha function
    //
    //     alpha(opacity=88)
    //
    private function parseAlpha()
    {
        if ( ! $this->match('/^\(opacity=/i')) {
            return;
        }

        $value = $this->match('/^[0-9]+/');
        if ($value === null) {
            $value = $this->match('parseEntitiesVariable');
        }

        if ($value !== null) {
			$this->expect(')');
            return new \Less\Node\Alpha($value);
        }
    }


    //
    // A Selector Element
    //
    //     div
    //     + h1
    //     #socks
    //     input[type="text"]
    //
    // Elements are the building blocks for Selectors,
    // they are made out of a `Combinator` (see combinator rule),
    // and an element name, such as a tag a class, or `*`.
    //
    private function parseElement()
    {
        $c = $this->match('parseCombinator');
        $e = $this->match('/^(?:\d+\.\d+|\d+)%/') ?:
			 $this->match('/^(?:[.#]?|:*)(?:[\w-]|\\\\(?:[a-fA-F0-9]{1,6} ?|[^a-fA-F0-9]))+/') ?:
             $this->match('*') ?:
             $this->match('parseAttribute') ?:
             $this->match('/^\([^)@]+\)/');

		if (!$e && $this->match('(') && ($v = $this->match('parseEntitiesVariable')) && $this->match(')')) {
			$e = new \Less\Node\Paren($v);
		}

        if ($e) {
            return new \Less\Node\Element($c, $e, $this->pos);
        }

        if ($c->value && $c->value[0] === '&') {
          return new \Less\Node\Element($c, null, $this->pos);
        }
    }

    //
    // Combinators combine elements together, in a Selector.
    //
    // Because our parser isn't white-space sensitive, special care
    // has to be taken, when parsing the descendant combinator, ` `,
    // as it's an empty space. We have to check the previous character
    // in the input, to see if it's a ` ` character.
    //
    private function parseCombinator()
    {
        $c = isset($this->input[$this->pos]) ? $this->input[$this->pos] : '';
        if ($c === '>' || $c === '+' || $c === '~') {

            $this->pos++;
            while ($this->input[$this->pos] === ' ') {
                $this->pos++;
            }
            return new \Less\Node\Combinator($c);
        } elseif ($c === '&') {

            $match = '&';
            $this->pos++;
            if ($this->input[$this->pos] === ' ') {
                $match = '& ';
            }
            while ($this->input[$this->pos] === ' ') {
                $this->pos++;
            }
            return new \Less\Node\Combinator($match);
        } elseif ($this->pos > 0 && (preg_match('/\s/', $this->input[$this->pos - 1]))) {
            return new \Less\Node\Combinator(' ');
        } else {
            return new \Less\Node\Combinator();
        }
    }

    //
    // A CSS Selector
    //
    //     .class > div + h1
    //     li a:hover
    //
    // Selectors are made out of one or more Elements, see above.
    //
    private function parseSelector()
    {
        $elements = array();

		if ($this->match('(')) {
			$sel = $this->match('parseEntity');
			$this->expect(')');
			return new \Less\Node\Selector(array(new \Less\Node\Element('', $sel, $this->pos)));
		}

        while ($e = $this->match('parseElement')) {
            $elements[] = $e;
            if ($this->peek('{') || $this->peek('}') || $this->peek(';') || $this->peek(',')) {
                break;
            }
        }
        if (count($elements) > 0) {
            return new \Less\Node\Selector($elements);
        }
    }

    private function parseTag()
    {
        return $this->match('/^[a-zA-Z][a-zA-Z-]*[0-9]?/') ?: $this->match('*');
    }

    private function parseAttribute()
    {
        if (! $this->match('[')) {
            return;
        }

        $attr = '';

        if ($key = $this->match('/^[a-zA-Z-]+/') ?: $this->match('parseEntitiesQuoted')) {
            if (($op = $this->match('/^[|~*$^]?=/')) &&
                ($val = $this->match('parseEntitiesQuoted') ?: $this->match('/^[\w-]+/'))) {
                if ( ! is_string($val)) {
                    $val = $val->toCss();
                }
                $attr = $key.$op.$val;
            } else {
                $attr = $key;
            }
        }

        if (! $this->match(']')) {
            return;
        }

        if ($attr) {
            return "[" . $attr . "]";
        }
    }

    //
    // The `block` rule is used by `ruleset` and `mixin.definition`.
    // It's a wrapper around the `primary` rule, with added `{}`.
    //
    private function parseBlock()
    {
        if ($this->match('{') && (is_array($content = $this->match('parsePrimary'))) && $this->match('}')) {
            return $content;
        }
    }

    //
    // div, .class, body > p {...}
    //
    private function parseRuleset()
    {
        $selectors = array();
        $start = $this->pos;

        while ($s = $this->match('parseSelector')) {
            $selectors[] = $s;
            $this->match('parseComment');
            if ( ! $this->match(',')) {
                break;
            }
            $this->match('parseComment');
        }

        if (count($selectors) > 0 && (is_array($rules = $this->match('parseBlock')))) {
            return new \Less\Node\Ruleset($selectors, $rules, $this->env->strictImports);
        } else {
            // Backtrack
            $this->pos = $start;
            $this->sync();
        }
    }

    private function parseRule()
    {
        $start = $this->pos;
        $c = isset($this->input[$this->pos]) ? $this->input[$this->pos] : '';

        if ($c === '.' || $c === '#' || $c === '&') {
            return;
        }

        if ($name = $this->match('parseVariable') ?: $this->match('parseProperty')) {
            if (($name[0] != '@') && preg_match('/^([^@+\/\'"*`(;{}-]*);/', $this->current, $match)) {
                $this->pos += strlen($match[0]) - 1;
                $value = new \Less\Node\Anonymous($match[1]);
            } else if ($name === "font") {
                $value = $this->match('parseFont');
            } else {
                $value = $this->match('parseValue');
            }
            $important = $this->match('parseImportant');

            if ($value && $this->match('parseEnd')) {
                return new \Less\Node\Rule($name, $value, $important, $start);
            } else {
                // Backtrack
                $this->pos = $start;
                $this->sync();
            }
        }
    }

    //
    // An @import directive
    //
    //     @import "lib";
    //
    // Depending on our environment, importing is done differently:
    // In the browser, it's an XHR request, in Node, it would be a
    // file-system operation. The function used for importing is
    // stored in `import`, which we pass to the Import constructor.
    //
    private function parseImport()
    {
		$index = $this->pos;
		$dir = $this->match('/^@import(?:-(once))?\s+/');

		if ($dir && ($path = $this->match('parseEntitiesQuoted') ?: $this->match('parseEntitiesUrl'))) {
			$features = $this->match('parseMediaFeatures');
			if ($this->match(';'))
				return new \Less\Node\Import($path, $this->path, $features, ($dir[0] == 'once'));
		}
    }

	private function parseMediaFeature() {
		$nodes = array();

		do {
			if ($e = $this->match('parseEntitiesKeyword')) {
				$nodes[] = $e;
			} elseif ($this->match('(')) {
				$p = $this->match('parseProperty');
				$e = $this->match('parseEntity');
				if ($this->match(')')) {
					if ($p && $e) {
						$nodes[] = new \Less\Node\Paren(new \Less\Node\Rule($p, $e, null, $this->pos, true));
					} elseif ($e) {
						$nodes[] = new \Less\Node\Paren($e);
					} else {
						return null;
					}
				} else
					return null;
			}
		} while ($e);

		if ($nodes) {
			return new \Less\Node\Expression($nodes);
		}
	}

	private function parseMediaFeatures() {
		$features = array();

		do {
			if ($e = $this->match('parseMediaFeature')) {
				$features[] = $e;
				if (!$this->match(',')) break;
			} elseif ($e = $this->match('parseEntitiesVariable')) {
				$features[] = $e;
				if (!$this->match(',')) break;
			}
		} while ($e);

		return $features ? $features : null;
	}

	private function parseMedia() {
		if ($this->match('/^@media/')) {
			$features = $this->match('parseMediaFeatures');

			if ($rules = $this->match('parseBlock')) {
				return new \Less\Node\Media($rules, $features);
			}
		}
	}

    //
    // A CSS Directive
    //
    //     @charset "utf-8";
    //
    private function parseDirective()
    {
        if (! $this->peek('@')) {
            return;
        }

        if ($value = ($this->match('parseImport') ?: $this->match('parseMedia'))) {
            return $value;
        } elseif ($name = ($this->match('/^@page|^@keyframes/') ?: $this->match('/^@(?:-webkit-|-moz-|-o-|-ms-)[a-z0-9-]+/'))) {
            $types = trim($this->match('/^[^{]+/') ?: '');
            if ($rules = $this->match('parseBlock')) {
                return new \Less\Node\Directive($name . " " . $types, $rules);
            }
        } else if ($name = $this->match('/^@[-a-z]+/')) {

            if ($name === '@font-face') {
                if ($rules = $this->match('parseBlock')) {
                    return new \Less\Node\Directive($name, $rules);
                }
            } else if (($value = $this->match('parseEntity')) && $this->match(';')) {
                 return new \Less\Node\Directive($name, $value);
            }
        }
    }

    private function parseFont()
    {
        $value = array();
        $expression = array();

        while ($e = $this->match('parseShorthand') ?: $this->match('parseEntity')) {
            $expression[] = $e;
        }
        $value[] = new \Less\Node\Expression($expression);

        if ($this->match(',')) {
            while ($e = $this->match('parseExpression')) {
                $value[] = $e;
                if (! $this->match(',')) {
                    break;
                }
            }
        }
        return new \Less\Node\Value($value);
    }

    //
    // A Value is a comma-delimited list of Expressions
    //
    //     font-family: Baskerville, Georgia, serif;
    //
    // In a Rule, a Value represents everything after the `:`,
    // and before the `;`.
    //
    private function parseValue ()
    {
        $expressions = array();

        while ($e = $this->match('parseExpression')) {
            $expressions[] = $e;
            if (! $this->match(',')) {
                break;
            }
        }

        if (count($expressions) > 0) {
            return new \Less\Node\Value($expressions);
        }
    }

    private function parseImportant ()
    {
        if ($this->peek('!')) {
            return $this->match('/^! *important/');
        }
    }

    private function parseSub ()
    {
        if ($this->match('(') && ($e = $this->match('parseExpression')) && $this->match(')')) {
            return $e;
        }
    }

    private function parseMultiplication() {
        $operation = false;
        if ($m = $this->match('parseOperand')) {
            while (!$this->peek('/^\/\*/') && ($op = ($this->match('/') ?: $this->match('*'))) && ($a = $this->match('parseOperand'))) {
                $operation = new \Less\Node\Operation($op, array($operation ?: $m, $a));
            }
            return $operation ?: $m;
        }
    }

    private function parseAddition ()
    {
        $operation = false;
        if ($m = $this->match('parseMultiplication')) {
            while (($op = $this->match('/^[-+]\s+/') ?: ( $this->input[$this->pos - 1] != ' ' ? ($this->match('+') ?: $this->match('-')) : false )) && ($a = $this->match('parseMultiplication'))) {
                $operation = new \Less\Node\Operation($op, array($operation ?: $m, $a));
            }
            return $operation ?: $m;
        }
    }

	private function parseConditions() {
		$index = $this->pos;
		$condition = null;
		if ($a = $this->match('parseCondition')) {
			while ($this->match(',') && ($b = $this->match('parseCondition'))) {
				$condition = new \Less\Node\Condition('or', $condition ?: $a, $b, $index);
			}
			return $condition ?: $a;
		}
	}

	private function parseCondition() {
		$index = $this->pos;
		$negate = false;

		if ($this->match('/^not/')) $negate = true;
		$this->expect('(');
		if ($a = ($this->match('parseAddition') ?: $this->match('parseEntitiesKeyword') ?: $this->match('parseEntitiesQuoted')) ) {
			if ($op = $this->match('/^(?:>=|=<|[<=>])/')) {
				if ($b = ($this->match('parseAddition') ?: $this->match('parseEntitiesKeyword') ?: $this->match('parseEntitiesQuoted'))) {
					$c = new \Less\Node\Condition($op, $a, $b, $index, $negate);
				} else {
					throw new \Less\Exception\ParserException('Unexpected expression');
				}
			} else {
				$c = new \Less\Node\Condition('=', $a, new \Less\Node\Keyword('true'), $index, $negate);
			}
			$this->expect(')');
			return $this->match('/^and/') ? new \Less\Node\Condition('and', $c, $this->match('parseCondition')) : $c;
		}
	}

    //
    // An operand is anything that can be part of an operation,
    // such as a Color, or a Variable
    //
    private function parseOperand ()
    {
        $negate = false;
        $p = isset($this->input[$this->pos + 1]) ? $this->input[$this->pos + 1] : '';
        if ($this->peek('-') && ($p === '@' || $p === '(')) {
            $negate = $this->match('-');
        }
        $o = $this->match('parseSub') ?:
             $this->match('parseEntitiesDimension') ?:
             $this->match('parseEntitiesColor') ?:
             $this->match('parseEntitiesVariable') ?:
             $this->match('parseEntitiesCall');

        return ($negate ? new \Less\Node\Operation('*', array( new \Less\Node\Dimension(-1), $o)) : $o);
    }

    //
    // Expressions either represent mathematical operations,
    // or white-space delimited Entities.
    //
    //     1px solid black
    //     @var * 2
    //
    private function parseExpression ()
    {
        $entities = array();

        while ($e = $this->match('parseAddition') ?: $this->match('parseEntity')) {
            $entities[] = $e;
        }
        if (count($entities) > 0) {
            return new \Less\Node\Expression($entities);
        }
    }

    private function parseProperty ()
    {
        if ($name = $this->match('/^(\*?-?[-a-z_0-9]+)\s*:/')) {
            return $name[1];
        }
    }

}
