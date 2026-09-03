<?php
/**
 * Packed offset→depth map for large documents.
 * PHP associative arrays cost ~70+ bytes/entry; this keeps ~9 bytes/entry (int64 off + uint8 depth)
 * and supports ArrayAccess/Iterator so existing $this->offset_depths[$off] / foreach keep working.
 */
class LomPackedOffsetDepths implements ArrayAccess, Countable, IteratorAggregate {
	/** @var SplFixedArray */
	private $offsets;
	/** @var SplFixedArray */
	private $depths;
	private $n = 0;
	private $thawed = null;

	public static function build_from_code($code, $must_self = false, $must_doctype = false, $must_cdata = false, $must_comment = false, $must_pi = false, $must_asp = false) {
		$len = strlen($code);
		$cap = ($len >> 4) + 64;
		$offs = new SplFixedArray($cap);
		$deps = new SplFixedArray($cap);
		$n = 0;
		$depth = 0;
		$offset = 0;
		if($len === 0) {
			$m = new self();
			$m->offsets = new SplFixedArray(0);
			$m->depths = new SplFixedArray(0);
			$m->n = 0;
			return $m;
		}
		if($code[0] !== '<') {
			$offs[$n] = 0;
			$deps[$n] = 0;
			$n++;
		}
		while(($offset = strpos($code, '<', $offset)) !== false) {
			if($n >= $cap) {
				$cap *= 2;
				$offs->setSize($cap);
				$deps->setSize($cap);
			}
			$offs[$n] = $offset;
			$deps[$n] = max(0, min(255, $depth));
			$n++;
			$next = $offset + 1;
			if($next >= $len) {
				break;
			}
			$c1 = $code[$next];
			if($c1 === '/') {
				$depth--;
				$gt = strpos($code, '>', $next);
				$offset = ($gt === false) ? $len : $gt + 1;
				continue;
			}
			if($must_comment && $c1 === '!' && substr($code, $next, 3) === '!--') {
				$end = strpos($code, '-->', $next);
				$offset = ($end === false) ? $len : $end + 3;
				continue;
			}
			if($must_cdata && $c1 === '!' && substr($code, $next, 8) === '![CDATA[') {
				$end = strpos($code, ']]>', $next);
				$offset = ($end === false) ? $len : $end + 3;
				continue;
			}
			if($must_doctype && $c1 === '!' && strncasecmp(substr($code, $offset, 9), '<!DOCTYPE', 9) === 0) {
				$gt = strpos($code, '>', $next);
				$offset = ($gt === false) ? $len : $gt + 1;
				continue;
			}
			if($must_pi && $c1 === '?') {
				$end = strpos($code, '?>', $next);
				$offset = ($end === false) ? $len : $end + 2;
				continue;
			}
			if($must_asp && $c1 === '%') {
				$end = strpos($code, '%>', $next);
				$offset = ($end === false) ? $len : $end + 2;
				continue;
			}
			$gt = strpos($code, '>', $next);
			if($gt === false) {
				break;
			}
			$self = ($must_self && $gt > $next && $code[$gt - 1] === '/');
			if(!$self) {
				$depth++;
			}
			$offset = $gt + 1;
		}
		$offs->setSize($n);
		$deps->setSize($n);
		$m = new self();
		$m->offsets = $offs;
		$m->depths = $deps;
		$m->n = $n;
		return $m;
	}

	private function find($offset) {
		$lo = 0;
		$hi = $this->n;
		while($lo < $hi) {
			$mid = ($lo + $hi) >> 1;
			$v = $this->offsets[$mid];
			if($v < $offset) {
				$lo = $mid + 1;
			} elseif($v > $offset) {
				$hi = $mid;
			} else {
				return $mid;
			}
		}
		return -1;
	}

	public function toArray() {
		if($this->thawed !== null) {
			return $this->thawed;
		}
		$out = array();
		for($i = 0; $i < $this->n; $i++) {
			$out[$this->offsets[$i]] = $this->depths[$i];
		}
		return $out;
	}

	public function thaw() {
		if($this->thawed === null) {
			$this->thawed = $this->toArray();
		}
		return $this->thawed;
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->toArray());
	}

	public function offsetExists($offset): bool {
		if($this->thawed !== null) {
			return isset($this->thawed[$offset]);
		}
		return $this->find((int)$offset) >= 0;
	}

	public function offsetGet($offset): mixed {
		if($this->thawed !== null) {
			return $this->thawed[$offset] ?? null;
		}
		$i = $this->find((int)$offset);
		return ($i < 0) ? null : $this->depths[$i];
	}

	public function offsetSet($offset, $value): void {
		$this->thaw();
		if($offset === null) {
			$this->thawed[] = $value;
		} else {
			$this->thawed[$offset] = $value;
		}
	}

	public function offsetUnset($offset): void {
		$this->thaw();
		unset($this->thawed[$offset]);
	}

	public function count(): int {
		if($this->thawed !== null) {
			return count($this->thawed);
		}
		return $this->n;
	}
}
