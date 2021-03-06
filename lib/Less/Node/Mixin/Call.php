<?php

namespace Less\Node\Mixin;

class Call
{
	private $selector;
	private $arguments;
	private $index;
	private $filename;

	public $important;

    public function __construct($elements, $args, $index, $filename, $important = false)
    {
        $this->selector =  new \Less\Node\Selector($elements);
        $this->arguments = $args;
        $this->index = $index;
		$this->filename = $filename;
		$this->important = $important;
    }

    public function compile($env)
    {
        $rules = array();
        $match = false;

        foreach($env->frames as $frame) {

            if ($mixins = $frame->find($this->selector, null, $env)) {

                $args = array_map(function ($a) use ($env) {
                    return $a->compile($env);
                }, $this->arguments);

                foreach ($mixins as $mixin) {
                    if ($mixin->match($args, $env)) {
                        try {
                            $rules = array_merge($rules, $mixin->compile($env, $this->arguments, $this->important)->rules);
                            $match = true;
                        } catch (Exception $e) {
                            throw new \Less\Exception\CompilerException($e->message, $e->index, null, $this->filename);
                        }
                    }
                }

                if ($match) {
                    return $rules;
                } else {
                    throw new \Less\Exception\CompilerException('No matching definition was found for `'.
						trim($this->selector->toCSS($env)) . '(' .
						implode(', ', array_map(function ($a) use($env) {
						  return $a->toCss($env);
						}, $this->arguments)) . ')`',
						$this->index, null, $this->filename);
                }
            }
        }

        throw new \Less\Exception\CompilerException(trim($this->selector->toCSS($env)) . " is undefined", $this->index);
    }
}
