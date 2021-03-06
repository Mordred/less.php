<?php

namespace Less\Node;

class Media {

	public $features;
	public $ruleset;

	public function __construct($value = array(), $features = array()) {
		$el = new Element('&', null, 0);
		$selectors = array(new Selector(array($el)));

		$this->features = new Value($features);
		$this->ruleset = new Ruleset($selectors, $value);
		$this->ruleset->allowImports = true;
	}

	public function toCSS($ctx, $env) {
		$features = $this->features->toCSS($env);

		$this->ruleset->root = (count($ctx) === 0 ?: isset($ctx[0]['multiMedia']) && $ctx[0]['multiMedia']);
		return '@media ' . $features . ($env->compress ? '{' : " {\n  ")
			. str_replace("\n", "\n  ", trim($this->ruleset->toCSS($ctx, $env)))
			. ($env->compress ? '}' : "\n}\n");
	}

	public function compile($env) {
		$blockIndex = count($env->mediaBlocks);
		$env->mediaPath[] = $this;
		$env->mediaBlocks[] = $this;

		$media = new Media(array(), array());
		$media->features = $this->features->compile($env);

		array_unshift($env->frames, $this->ruleset);
		$media->ruleset = $this->ruleset->compile($env);
		array_shift($env->frames);

		$env->mediaBlocks[$blockIndex] = $media;
		array_pop($env->mediaPath);

		return count($env->mediaPath) == 0 ? $media->compileTop($env) : $media->compileNested($env);
	}

	// TODO: Not sure if this is right...
	public function variable($name) {
		return $this->ruleset->variable($name);
	}

	public function find($selector) {
		return $this->ruleset->find($selector, $this);
	}

	public function rulesets() {
		return $this->ruleset->rulesets();
	}

	public function compileTop($env) {
		$result = $this;

		if (count($env->mediaBlocks) > 1) {
			$el = new Element('&', null, 0);
			$selectors = array(new Selector(array($el)));
			$result = new Ruleset($selectors, $env->mediaBlocks);
			$result->multiMedia = true;
		}

		$env->mediaBlocks = array();
		$env->mediaPath = array();

		return $result;
	}

	public function compileNested($env) {
		$path = array_merge($env->mediaPath, array($this));

		// Extract the media-query conditions separated with `,` (OR).
		foreach ($path as $key => $p) {
			$value = $p->features instanceof Value ? $p->features->value : $p->features;
			$path[$key] = is_array($value) ? $value : array($value);
		}

		// Trace all permutations to generate the resulting media-query.
		//
		// (a, b and c) with nested (d, e) ->
		//    a and d
		//    a and e
		//    b and c and d
		//    b and c and e
		$this->features = new Value(array_map(function($path) {
			$path = array_map(function($fragment) {
				return method_exists($fragment, 'toCSS') ? $fragment : new Anonymous($fragment);
			}, $path);

			for ($i = count($path) - 1; $i > 0; $i--) {
				array_splice($path, $i, 0, array(new Anonymous('and')));
			}

			return new Expression($path);
		}, $this->permute($path)));

        // Fake a tree-node that doesn't output anything.
        return new Ruleset(array(), array());
	}

	public function permute($arr) {
		if (!$arr)
			return array();

		if (count($arr) == 1)
			return $arr[0];

		$result = array();
		$rest = $this->permute(array_slice($arr, 1));
		foreach ($rest as $r) {
			foreach ($arr[0] as $a) {
				$result[] = array_merge(
					is_array($a) ? $a : array($a),
					is_array($r) ? $r : array($r)
				);
			}
		}
		return $result;
	}
}
