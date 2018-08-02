<?php namespace ProcessWire;
/**
 * ProcessWire Password Fieldtype
 *
 * Class to hold combined password/salt info. Uses Blowfish when possible.
 * Specially used by FieldtypePassword.
 * 
 * ProcessWire 3.x, Copyright 2016 by Ryan Cramer
 * https://processwire.com
 * 
 * @method setPass($value)
 *
 */

class Password extends Wire {

	/**
	 * @var array
	 * 
	 */
	protected $data = array(
		'salt' => '', 
		'hash' => '',
		);

	/**
	 * Does this Password match the given string?
	 *
	 * @param string $pass Password to compare
	 * @return bool
	 *
	 */
	public function matches($pass) {

		if(!strlen($pass)) return false;
		$hash = $this->hash($pass); 
		if(!strlen($hash)) return false;
		$updateNotify = false;

		if($this->isBlowfish($hash)) {
			$hash = substr($hash, 29);

		} else if($this->supportsBlowfish()) {
			// notify user they may want to change their password
			// to take advantage of blowfish hashing
			$updateNotify = true; 
		}

		if(strlen($hash) < 29) return false;

		$matches = ($hash === $this->data['hash']);

		if($matches && $updateNotify) {
			$this->message($this->_('The password system has recently been updated. Please change your password to complete the update for your account.'));
		}

		return $matches; 
	}

	/**
	 * Get a property via direct access ('salt' or 'hash')
	 * 
	 * #pw-group-internal
	 * 
	 * @param string $key
	 * @return mixed
	 *
	 */
	public function __get($key) {
		if($key == 'salt' && !$this->data['salt']) $this->data['salt'] = $this->salt();
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}

	/**
	 * Set a property 
	 * 
	 * #pw-group-internal
	 * 
	 * @param string $key
	 * @param mixed $value
	 *
	 */
	public function __set($key, $value) {

		if($key == 'pass') {
			// setting the password
			$this->setPass($value);

		} else if(array_key_exists($key, $this->data)) { 
			// something other than pass
			$this->data[$key] = $value; 
		}
	}

	/**
	 * Set the 'pass' to the given value
	 * 
	 * @param string $value
	 * @throws WireException if given invalid $value
	 *
	 */
	protected function ___setPass($value) {

		// if nothing supplied, then don't continue
		if(!strlen($value)) return;
		if(!is_string($value)) throw new WireException("Password must be a string"); 

		// first check to see if it actually changed
		if($this->data['salt'] && $this->data['hash']) {
			$hash = $this->hash($value);
			if($this->isBlowfish($hash)) $hash = substr($hash, 29);
			// if no change then return now
			if($hash === $this->data['hash']) return; 
		}

		// password has changed
		$this->trackChange('pass');

		// force reset by clearing out the salt, hash() will gen a new salt
		$this->data['salt'] = ''; 

		// generate the new hash
		$hash = $this->hash($value);

		// if it's a blowfish hash, separate the salt from the hash
		if($this->isBlowfish($hash)) {
			$this->data['salt'] = substr($hash, 0, 29); // previously 28
			$this->data['hash'] = substr($hash, 29);
		} else {
			$this->data['hash'] = $hash;
		}
	}

	/**
	 * Generate a random salt for the given hashType
	 *
	 * @return string
	 *
	 */
	protected function salt() {

		// if system doesn't support blowfish, return old style salt
		if(!$this->supportsBlowfish()) return md5($this->randomBase64String(44)); 

		// blowfish assumed from this point forward
		// use stronger blowfish mode if PHP version supports it 
		$salt = (version_compare(PHP_VERSION, '5.3.7') >= 0) ? '$2y' : '$2a';

		// cost parameter (04-31)
		$salt .= '$11$';
		// 22 random base64 characters
		$salt .= $this->randomBase64String(22);
		// plus trailing $
		$salt .= '$'; 

		return $salt;
	}

	/**
	 * Generate a truly random base64 string of a certain length
	 *
	 * This is largely taken from Anthony Ferrara's password_compat library:
	 * https://github.com/ircmaxell/password_compat/blob/master/lib/password.php
	 * Modified for camelCase, variable names, and function-based context by Ryan.
	 *
	 * @param int $requiredLength Length of string you want returned (default=22)
	 * @param array|bool $options Specify array of options or boolean to specify only `fast` option.
	 *  - `fast` (bool): Use fastest, not cryptographically secure method (default=false). 
	 *  - `test` (bool|array): Return tests in a string (bool true), or specify array(true) to return tests array (default=false).
	 *    Note that if the test option is used, then the fast option is disabled. 
	 * @return string|array Returns only array if you specify array for $test argument, otherwise returns string
	 *
	 */
	public function randomBase64String($requiredLength = 22, $options = array()) {
		
		$defaults = array(
			'fast' => false,
			'test' => false, 
		);

		if(is_array($options)) {
			$options = array_merge($defaults, $options);
		} else {
			if(is_bool($options)) $defaults['fast'] = $options;
			$options = $defaults; 
		}
		
		$buffer = '';
		$valid = false;
		$tests = array();
		$test = $options['test'];

		if($options['fast'] && !$test) {
			// fast mode for non-password use, uses only mt_rand() generated characters		
			$rawLength = $requiredLength;
			
		} else {
			// for password use, slower
			$rawLength = (int) ($requiredLength * 3 / 4 + 1);
		
			// mcrypt_create_iv 
			if((!$valid || $test) && function_exists('mcrypt_create_iv') && !defined('PHALANGER')) {
				// @operator added for PHP 7.1 which throws deprecated notice on this function call
				$buffer = @mcrypt_create_iv($rawLength, MCRYPT_DEV_URANDOM);
				if($buffer) $valid = true;
				if($test) $tests['mcrypt_create_iv'] = $buffer;
			} else if($test) {
				$tests['mcrypt_create_iv'] = '';
			}

			// PHP7 random_bytes
			if((!$valid || $test) && function_exists('random_bytes')) {
				try {
					$buffer = random_bytes($rawLength);
					if($buffer) $valid = true;
				} catch(\Exception $e) {
					$valid = false;
				}
				if($test) $tests['random_bytes'] = $buffer;
			} else if($test) {
				$tests['random_bytes'] = '';
			}

			// openssl_random_pseudo_bytes
			if((!$valid || $test) && function_exists('openssl_random_pseudo_bytes')) {
				$good = false;
				$buffer = openssl_random_pseudo_bytes($rawLength, $good);
				if($test) $tests['openssl_random_pseudo_bytes'] = $buffer . "\tNOTE=" . ($good ? 'strong' : 'NOT strong');
				if(!$good) $buffer = '';
				if($buffer) $valid = true;
			} else if($test) {
				$tests['openssl_random_pseudo_bytes'] = '';
			}

			// read from /dev/urandom
			if((!$valid || $test) && @is_readable('/dev/urandom')) {
				$f = fopen('/dev/urandom', 'r');
				$readLength = 0;
				if($test) $buffer = '';
				while($readLength < $rawLength) {
					$buffer .= fread($f, $rawLength - $readLength);
					$readLength = $this->_strlen($buffer);
				}
				fclose($f);
				if($readLength >= $rawLength) $valid = true;
				if($test) $tests['/dev/urandom'] = $buffer;
			} else if($test) {
				$tests['/dev/urandom'] = '';
			}
		}

		$bufferLength = $this->_strlen($buffer);
	
		// mt_rand() fast
		if(!$valid || $test || $bufferLength < $rawLength) {
			for($i = 0; $i < $rawLength; $i++) {
				if($i < $bufferLength) {
					$buffer[$i] = $buffer[$i] ^ chr(mt_rand(0, 255));
				} else {
					$buffer .= chr(mt_rand(0, 255));
				}
			}
			if($test) $tests['mt_rand'] = $buffer;
		}
		
		if($test) {
			// test mode
			$salt = '';
			foreach($tests as $name => $value) {
				$note = '';
				if(strpos($value, "\tNOTE=")) list($value, $note) = explode("\tNOTE=", $value); 
				$value = empty($value) ? 'N/A' : $this->randomBufferToSalt($value, $requiredLength);
				$_name = str_pad($name, 28, ' ', STR_PAD_LEFT);
				$tests[$name] = $value;
				$salt .= "\n$_name: $value $note";
			}
			$salt = is_array($test) ? $tests : ltrim($salt, "\n"); 
		} else {
			// regular random string mode
			$salt = $this->randomBufferToSalt($buffer, $requiredLength);
		}
		
		return $salt;
	}

	/**
	 * Given random buffer string of bytes return base64 encoded salt
	 * 
	 * @param string $buffer
	 * @param int $requiredLength
	 * @return string
	 * 
	 */
	protected function randomBufferToSalt($buffer, $requiredLength) {
		$c1 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'; // base64
		$c2 = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; // bcrypt64
		$salt = rtrim(base64_encode($buffer), '=');
		$salt = strtr($salt, $c1, $c2);
		$salt = substr($salt, 0, $requiredLength);
		return $salt;
	}

	/**
	 * Return string length, using mb_strlen() when available, or strlen() when not
	 * 
	 * @param string $s
	 * @return int
	 * 
	 */
	function _strlen($s) {
		return function_exists('mb_strlen') ? mb_strlen($s, '8bit') : strlen($s);
	}

	/**
 	 * Returns whether the given string is blowfish hashed
	 *
	 * @param string $str
	 * @return bool
	 *
	 */
	public function isBlowfish($str = '') {
		if(!strlen($str)) $str = $this->data['salt'];
		$prefix = substr($str, 0, 3); 
		return $prefix === '$2a' || $prefix === '$2x' || $prefix === '$2y'; 
	}

	/**
 	 * Returns whether the current system supports Blowfish
	 *
	 * @return bool
	 *
	 */
	public function supportsBlowfish() {
		return version_compare(PHP_VERSION, '5.3.0') >= 0 && defined("CRYPT_BLOWFISH") && CRYPT_BLOWFISH;
	}

	/**
	 * Given an unhashed password, generate a hash of the password for database storage and comparison
	 *
	 * Note: When blowfish, returns the entire blowfish string which has the salt as the first 28 characters. 
	 *
	 * @param string $pass Raw password
	 * @return string
	 * @throws WireException
	 *
	 */
	protected function hash($pass) {

		// if there is no salt yet, make one (for new pass or reset pass)
		if(strlen($this->data['salt']) < 28) $this->data['salt'] = $this->salt();

		// if system doesn't support blowfish, but has a blowfish salt, then reset it 
		if(!$this->supportsBlowfish() && $this->isBlowfish($this->data['salt'])) $this->data['salt'] = $this->salt();

		// salt we made (the one ultimately stored in DB)
		$salt1 = $this->data['salt'];

		// static salt stored in config.php
		$salt2 = (string) $this->wire('config')->userAuthSalt; 

		// auto-detect the hash type based on the format of the salt
		$hashType = $this->isBlowfish($salt1) ? 'blowfish' : $this->wire('config')->userAuthHashType;

		if(!$hashType) {
			// If there is no defined hash type, and the system doesn't support blowfish, then just use md5 (ancient backwards compatibility)
			$hash = md5($pass); 

		} else if($hashType == 'blowfish') {
			if(!$this->supportsBlowfish()) {
				throw new WireException("This version of PHP is not compatible with the passwords. Did passwords originate on a newer version of PHP?"); 
			}
			// our preferred method
			$hash = crypt($pass . $salt2, $salt1);

		} else {
			// older style, non-blowfish support
			// split the password in two
			$splitPass = str_split($pass, (strlen($pass) / 2) + 1); 
			// generate the hash
			$hash = hash($hashType, $salt1 . $splitPass[0] . $salt2 . $splitPass[1], false); 
		}

		if(!is_string($hash) || strlen($hash) <= 13) throw new WireException("Unable to generate password hash"); 

		return $hash; 
	}

	/**
	 * Return a pseudo-random alpha or alphanumeric character
	 * 
	 * @param int $qty Number of random characters requested
	 * @param bool $alphanumeric Specify true to allow digits in return value
	 * @param array $disallow Characters that may not be used in return value
	 * @return string
	 *
	 */
	public function randomAlpha($qty = 1, $alphanumeric = false, $disallow = array()) {
		$letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$digits = '0123456789';
		if($alphanumeric) $letters .= $digits;
		if($alphanumeric === 1) $letters = $digits; // digits only
		foreach($disallow as $c) {
			$letters = str_replace($c, '', $letters);
		}
		$value = '';
		for($x = 0; $x < $qty; $x++) {
			$n = mt_rand(0, strlen($letters) - 1);
			$value .= $letters[$n];
		}
		return $value;
	}

	/**
	 * Return cryptographically secure random alphanumeric, alpha or numeric string
	 * 
	 * This method does essentially the same thing as the randomAlpha() method except
	 * that it is alphanumeric by default, it uses a more cryptographically secure 
	 * method by default (and thus can be slower), and it provides for more $options. 
	 * 
	 * **Note about the `allow` option:**   
	 * If this option is used, it overrides the `alpha` and `numeric` options and creates a 
	 * string that has only the given characters. If given characters are not ASCII alpha or 
	 * numeric, then the `fast` option is always used, as the crypto-secure option does not 
	 * support non-alphanumeric characters. 
	 * 
	 * @param int $length Required length of string, or 0 for random length
	 * @param array $options Options to modify default behavior:
	 *  - `fast` (bool): Use fast, non-cryptographically secure method instead? (default=false)
	 *  - `alpha` (bool): Allow ASCII alphabetic characters? (default=true)
	 *  - `upper` (bool): Allow uppercase ASCII alphabetic characters? (default=true)
	 *  - `lower` (bool): Allow lowercase ASCII alphabetic characters? (default=true)
	 *  - `numeric` (bool): Allow numeric characters 0123456789? (default=true)
	 *  - `allow` (array|string): Only allow these ASCII alpha or digit characters, see notes. (default='')
	 *  - `disallow` (array|string): Do not allow these characters. (default='')
	 *  - `minLength` (int): If $length argument is 0, minimum length of returned string. (default=10)
	 *  - `maxLength` (int): If $length argument is 0, maximum length of returned string. (default=40)
	 * @return string
	 * @throws WireException
	 * 
	 */
	public function randomAlnum($length = 0, array $options = array()) {
		
		$defaults = array(
			'fast' => false,
			'alpha' => true, 
			'upper' => true, 
			'lower' => true, 
			'numeric' => true, 
			'allow' => '', 
			'disallow' => array(),
			'minLength' => 10, 
			'maxLength' => 40,
		);
		
		$options = array_merge($defaults, $options);
		$allowed = '';
		$value = '';
		
		if($length < 1) {
			$length = mt_rand($options['minLength'], $options['maxLength']);
		}
		
		if(is_string($options['disallow'])) {
			// convert to array
			$options['disallow'] = explode('', $options['disallow']); 
		}
		
		if(is_array($options['allow'])) {
			// convert to string
			$options['allow'] = implode('', $options['allow']);
		}
		
		if(strlen($options['allow'])) {
			// only fast option supports non-alphanumeric characters
			if(!ctype_alnum($options['allow'])) $options['fast'] = true;
			$allowed = $options['allow'];
			
		} else {
			if($options['alpha']) {
				if($options['upper']) $allowed .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
				if($options['lower']) $allowed .= 'abcdefghijklmnopqrstuvwxyz';
			}
			if($options['numeric']) {
				$allowed .= '0123456789';
			}
		}
	
		if(count($options['disallow'])) {
			$allowed = str_replace($options['disallow'], '', $allowed); 
		}
		
		if(!strlen($allowed)) {
			throw new WireException("Specified options prevent any alnum string from being created"); 
		}
		
		if($options['fast']) {
			// fast method
			for($x = 0; $x < $length; $x++) {
				$n = mt_rand(0, strlen($allowed) - 1);
				$value .= $allowed[$n];
			}
		} else {
			// slow but cryptographically secure method
			$qty = 0;
			do {
				$baseLen = strlen($allowed) < 50 ? $length * 3 : $length * 2;
				$baseStr = $this->randomBase64String($baseLen);
				for($n = 0; $n < strlen($baseStr); $n++) {
					$c = $baseStr[$n];
					if(strpos($allowed, $c) === false) continue;
					$value .= $c;
					if(++$qty >= $length) break;
				}
			} while($qty < $length);
		}
		
		return $value;
	}

	/**
	 * Return string of random digits
	 * 
	 * @param int $length Required length of string or 0 for random length
	 * @param array $options See options for randomAlnum() method
	 * @return string
	 * 
	 */
	public function randomDigits($length = 0, array $options = array()) {
		$options['alpha'] = false;
		return $this->randomAlnum($length, $options);
	}

	/**
	 * Generate and return a random password
	 * 
	 * Default settings of this method are to generate a random but readable password without characters that
	 * tend to have readability issues, and using only ASCII characters (for broadest keyboard compatibility).
	 * 
	 * @param array $options Specify any of the following options (all optional):
	 *  - `minLength` (int): Minimum lenth of returned value (default=7).
	 *  - `maxLength` (int): Maximum lenth of returned value, will be exceeded if needed to meet other options (default=15).
	 *  - `minLower` (int): Minimum number of lowercase characters required (default=1). 
	 *  - `minUpper` (int): Minimum number of uppercase characters required (default=1).
	 *  - `maxUpper` (int): Maximum number of uppercase characters allowed (0=any, -1=none, default=3).
	 *  - `minDigits` (int): Minimum number of digits required (default=1).
	 *  - `maxDigits` (int): Maximum number of digits allowed (0=any, -1=none, default=0). 
	 *  - `minSymbols` (int): Minimum number of non-alpha, non-digit symbols required (default=0).
	 *  - `maxSymbols` (int): Maximum number of non-alpha, non-digit symbols to allow (0=any, -1=none, default=3).
	 *  - `useSymbols` (array): Array of characters to use as "symbols" in returned value (see method for default).
	 *  - `disallow` (array): Disallowed characters that may be confused with others (default=O,0,I,1,l).
	 *
	 * @return string
	 * 
	 */
	public function randomPass(array $options = array()) {

		$defaults = array(
			'minLength' => 7, 
			'maxLength' => 15,
			'minUpper' => 1, 
			'maxUpper' => 3, 
			'minLower' => 1, 
			'minDigits' => 1, 
			'maxDigits' => 0, 
			'minSymbols' => 0, 
			'maxSymbols' => 3, 
			'useSymbols' => array('@', '#', '$', '%', '^', '*', '_', '-', '+', '?', '(', ')', '!', '.', '=', '/'),
			'disallow' => array('O', '0', 'I', '1', 'l'), 
		);

		$options = array_merge($defaults, $options);
		$length = mt_rand($options['minLength'], $options['maxLength']);
		$base64Symbols = array('/' , '.');
		$_disallow = array(); // with both upper and lower versions
		
		foreach($options['disallow'] as $c) {
			$c = strtolower($c);
			$_disallow[$c] = $c;
			$c = strtoupper($c);
			$_disallow[$c] = $c;
		}

		// build foundation of password using base64 string
		do {
			$value = $this->randomBase64String($length);
			$valid = preg_match('/[A-Z]/i', $value) && preg_match('/[0-9]/', $value);
		} while(!$valid);

		// limit amount of characters that are too common in base64 string
		foreach($base64Symbols as $char) {
			if(strpos($value, $char) === false) continue;
			$c = $this->randomAlpha(1, true, $options['disallow']);
			$value = str_replace($char, $c, $value);
		}

		// manage quantity of symbols
		if($options['maxSymbols'] > -1) {
			// ensure there are a certain quantity of symbols present
			if($options['maxSymbols'] === 0) {
				$numSymbols = mt_rand($options['minSymbols'], floor(strlen($value) / 2));
			} else {
				$numSymbols = mt_rand($options['minSymbols'], $options['maxSymbols']);
			}
			$symbols = $options['useSymbols'];
			shuffle($symbols);
			for($n = 0; $n < $numSymbols; $n++) {
				$symbol = array_shift($symbols);
				$value .= $symbol;
			}
		} else {
			// no symbols, remove those commonly added in base64 string
			$options['disallow'] = array_merge($options['disallow'], $base64Symbols);
		}

		// manage quantity of uppercase characters
		if($options['maxUpper'] > 0 || ($options['minUpper'] > 0 && $options['maxUpper'] > -1)) {
			// limit or establish the number of uppercase characters
			if(!$options['maxUpper']) $options['maxUpper'] = floor(strlen($value) / 2);
			$numUpper = mt_rand($options['minUpper'], $options['maxUpper']);
			if($numUpper) {
				$value = strtolower($value);
				$test = $this->wire('sanitizer')->alpha($value);
				if(strlen($test) < $numUpper) {
					// there aren't enough characters present to meet requirements, so add some	
					$value .= $this->randomAlpha($numUpper - strlen($test), false, $_disallow);
				}
				for($i = 0; $i < strlen($value); $i++) {
					$c = strtoupper($value[$i]);
					if(in_array($c, $options['disallow'])) continue;
					if($c !== $value[$i]) $value[$i] = $c;
					if($c >= 'A' && $c <= 'Z') $numUpper--;
					if(!$numUpper) break;
				}
				// still need more? append new characters as needed
				if($numUpper) $value .= strtoupper($this->randomAlpha($numUpper, false, $_disallow));
			}

		} else if($options['maxUpper'] < 0) {
			// disallow upper
			$value = strtolower($value);
		}
		
		// manage quantity of lowercase characters
		if($options['minLower'] > 0) {
			$test = preg_replace('/[^a-z]/', '', $value);
			if(strlen($test) < $options['minLower']) {
				// needs more lowercase
				$value .= strtolower($this->randomAlpha($options['minLower'] - strlen($test), false, $_disallow));
			}
		}
	
		// manage quantity of required digits
		if($options['minDigits'] > 0) {
			$test = $this->wire('sanitizer')->digits($value);
			$test = str_replace($options['disallow'], '', $test);
			$numDigits = $options['minDigits'] - strlen($test);
			if($numDigits > 0) {
				$value .= $this->randomAlpha($numDigits, 1, $options['disallow']);	
			}
		}
		if($options['maxDigits'] > 0 || $options['maxDigits'] == -1) {
			// a maximum number of digits specified
			$numDigits = 0;
			for($n = 0; $n < strlen($value); $n++) {
				$c = $value[$n];
				$isDigit = ctype_digit($c);
				if($isDigit) $numDigits++;
				if($isDigit && $numDigits > $options['maxDigits']) {
					// convert digit to alpha
					$value[$n] = strtolower($this->randomAlpha(1, false, $_disallow));
				}
			}
		}

		// replace any disallowed characters
		foreach($options['disallow'] as $char) {
			$pos = strpos($value, $char);
			if($pos === false) continue;
			if(ctype_digit($char)) {
				$c = $this->randomAlpha(1, 1, $_disallow);
			} else if(strtoupper($char) === $char) {
				$c = strtoupper($this->randomAlpha(1, false, $_disallow));
			} else {
				$c = strtolower($this->randomAlpha(1, false, $_disallow));
			}
			$value = str_replace($char, $c, $value);
		}
	
		// randomize, in case any operations above need it
		$value = str_split($value);
		shuffle($value);
		$value = implode('', $value);

		return $value;
	}
	
	public function __toString() {
		return (string) $this->data['hash'];
	}

}

