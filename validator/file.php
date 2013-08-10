<?php
/**
*
* @package phpBB Translation Validator
* @copyright (c) 2013 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

class phpbb_ext_official_translationvalidator_validator_file
{
	/**
	* @var phpbb_ext_official_translationvalidator_validator_key
	*/
	protected $key_validator;

	/**
	* @var phpbb_ext_official_translationvalidator_message_collection
	*/
	protected $messages;

	/**
	* @var phpbb_user
	*/
	protected $user;

	/**
	* Path to the folder where the languages are.
	* @var string
	*/
	protected $package_path;

	/**
	* Path to the folder where the language to validate is,
	* including $package_path.
	* @var string
	*/
	protected $validate_language_dir;

	/**
	* Path to the folder where the language to compare against is,
	* including $package_path.
	* @var string
	*/
	protected $validate_against_dir;

	/**
	* Construct
	*
	* @param	phpbb_ext_official_translationvalidator_validator_key	$key_validator			Validator for the language key elements
	* @param	phpbb_ext_official_translationvalidator_message_collection	$emessage_collection	Collection where we push our messages to
	* @param	phpbb_user	$user		Current user object, only required for lang()
	* @param	string	$lang_path		Path to the folder where the languages are
	* @return	phpbb_ext_official_translationvalidator_validator_file
	*/
	public function __construct(phpbb_ext_official_translationvalidator_validator_key $key_validator, $emessage_collection, phpbb_user $user, $lang_path)
	{
		$this->key_validator = $key_validator;
		$this->messages = $emessage_collection;
		$this->user = $user;
		$this->package_path = (string) $lang_path;
	}

	/**
	* Set the iso of the language we validate
	*
	* @param	string	$language
	* @return	phpbb_ext_official_translationvalidator_validator_file
	*/
	public function set_validate_language($language)
	{
		$this->validate_language_dir = $this->package_path . $language;

		if (!file_exists($this->validate_language_dir))
		{
			throw new OutOfBoundsException($this->user->lang('INVALID_LANGUAGE', $language));
		}

		return $this;
	}

	/**
	* Set the iso of the language we compare against
	*
	* @param	string	$language
	* @return	phpbb_ext_official_translationvalidator_validator_file
	*/
	public function set_validate_against($language)
	{
		$this->validate_against_dir = $this->package_path . $language;

		if (!file_exists($this->validate_against_dir))
		{
			throw new OutOfBoundsException($this->user->lang('INVALID_LANGUAGE', $language));
		}

		return $this;
	}

	/**
	* Decides which validation function to use
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate($file)
	{
		$this->validate_line_endings($file);
		if (substr($file, -4) === '.php')
		{
			$this->validate_defined_in_phpbb($file);
		}

		if (strpos($file, 'email/') === 0 && substr($file, -4) === '.txt')
		{
			$this->validate_email($file);
		}
		else if (strpos($file, 'help_') === 0 && substr($file, -4) === '.php')
		{
			$this->validate_help_file($file);
		}
		else if ($file == 'search_synonyms.php')
		{
			$this->validate_search_synonyms_file($file);
		}
		else if ($file == 'search_ignore_words.php')
		{
			$this->validate_search_ignore_words_file($file);
		}
		else if (substr($file, -4) === '.php')
		{
			$this->validate_lang_file($file);
		}
		else if (substr($file, -9) === 'index.htm')
		{
			$this->validate_index_file($file);
		}
		else if ($file === 'iso.txt')
		{
			$this->validate_iso_file($file);
		}
		else
		{
			$this->messages->push('debug', $this->user->lang('FILE_NOT_VALIDATED', $file));
		}
	}

	/**
	* Validates a normal language file
	*
	* Files should not produce any output.
	* Files should only define the $lang variable.
	* Files must have all language keys defined in the source file.
	* Files should not have additional language keys.
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_lang_file($file)
	{
		ob_start();
		include($this->validate_language_dir . '/' . $file);

		$defined_variables = get_defined_vars();
		if (sizeof($defined_variables) != 2 || !isset($defined_variables['lang']) || gettype($defined_variables['lang']) != 'array')
		{
			$this->messages->push('fail', $this->user->lang('FILE_INVALID_VARS', $file, 'lang'));
			if (!isset($defined_variables['lang']) || gettype($defined_variables['lang']) != 'array')
			{
				return;
			}
		}

		$output = ob_get_contents();
		ob_end_clean();

		if ($output !== '')
		{
			$this->messages->push('fail', $this->user->lang('LANG_OUTPUT', $file, htmlspecialchars($output)));
		}

		$validate = $lang;
		unset($lang);

		include($this->validate_against_dir . '/' . $file);
		$against = $lang;
		unset($lang);

		foreach ($against as $against_lang_key => $against_language)
		{
			if (!isset($validate[$against_lang_key]))
			{
				$this->messages->push('fail', $this->user->lang('MISSING_KEY', $file, $against_lang_key));
				continue;
			}

			$this->key_validator->validate($file, $against_lang_key, $against_language, $validate[$against_lang_key]);
		}

		foreach ($validate as $validate_lang_key => $validate_language)
		{
			if (!isset($against[$validate_lang_key]))
			{
				$this->messages->push('fail', $this->user->lang('INVALID_KEY', $file, $validate_lang_key));
			}
		}
	}

	/**
	* Validates a email .txt file
	*
	* Emails must have a subject when the source file has one, otherwise must not have one.
	* Emails must have a signature when the source file has one, otherwise must not have one.
	* Emails should use template vars, used by the source file.
	* Emails should not use additional template vars.
	* Emails should not use any HTML.
	* Emails should contain a newline at their end.
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_email($file)
	{
		$against_file = (string) file_get_contents($this->validate_against_dir . '/' . $file);
		$validate_file = (string) file_get_contents($this->validate_language_dir . '/' . $file);

		$against_file = explode("\n", $against_file);
		$validate_file = explode("\n", $validate_file);

		// One language contains a subject, the other one does not
		if (strpos($against_file[0], 'Subject: ') === 0 && strpos($validate_file[0], 'Subject: ') !== 0)
		{
			$this->messages->push('fail', $this->user->lang('EMAIL_MISSING_SUBJECT', $file));
		}
		else if (strpos($against_file[0], 'Subject: ') !== 0 && strpos($validate_file[0], 'Subject: ') === 0)
		{
			$this->messages->push('fail', $this->user->lang('EMAIL_INVALID_SUBJECT', $file));
		}

		// One language contains the signature, the other one does not
		if ((end($against_file) === '{EMAIL_SIG}' || prev($against_file) === '{EMAIL_SIG}')
			&& end($validate_file) !== '{EMAIL_SIG}' && prev($validate_file) !== '{EMAIL_SIG}')
		{
			$this->messages->push('fail', $this->user->lang('EMAIL_MISSING_SIG', $file));
		}
		else if ((end($validate_file) === '{EMAIL_SIG}' || prev($validate_file) === '{EMAIL_SIG}')
			&& end($against_file) !== '{EMAIL_SIG}' && prev($against_file) !== '{EMAIL_SIG}')
		{
			$this->messages->push('fail', $this->user->lang('EMAIL_INVALID_SIG', $file));
		}

		$validate_template_vars = $against_template_vars = array();
		preg_match_all('/{.+?}/', implode("\n", $validate_file), $validate_template_vars);
		preg_match_all('/{.+?}/', implode("\n", $against_file), $against_template_vars);


		$additional_against = array_diff($against_template_vars[0], $validate_template_vars[0]);
		$additional_validate = array_diff($validate_template_vars[0], $against_template_vars[0]);

		// Check the used template variables
		if (!empty($additional_validate))
		{
			$this->messages->push('warning', $this->user->lang('EMAIL_ADDITIONAL_VARS', $file, implode(', ', $additional_validate)));
		}

		if (!empty($additional_against))
		{
			$this->messages->push('warning', $this->user->lang('EMAIL_MISSING_VARS', $file, implode(', ', $additional_against)));
		}

		$validate_html = array();
		preg_match_all('/\<.+?\>/', implode("\n", $validate_file), $validate_html);
		if (!empty($validate_html) && !empty($validate_html[0]))
		{
			foreach ($validate_html[0] as $possible_html)
			{
				if (substr($possible_html, 0, 5) !== '<!-- ' || substr($possible_html, -4) !== ' -->')
				{
					$this->messages->push('fail', $this->user->lang('EMAIL_ADDITIONAL_HTML', $file, htmlspecialchars($possible_html)));
				}
			}
		}

		// Check for new liens at the end of the file
		if (end($validate_file) !== '')
		{
			$this->messages->push('notice', $this->user->lang('EMAIL_MISSING_NEWLINE', $file));
		}
	}

	/**
	* Validates a help_*.php file
	*
	* Files must only contain the variable $help.
	* This variable must be an array of arrays.
	* Subarrays must only have the indexes 0 and 1,
	* with 0 being the headline and 1 being the description.
	*
	* Files must contain an entry with 0 and 1 being '--',
	* causing the column break in the page.
	*
	* @todo		Check for template vars and html
	* @todo		Check for triple --- and other typos of it.
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_help_file($file)
	{
		include($this->validate_language_dir . '/' . $file);

		$defined_variables = get_defined_vars();
		if (sizeof($defined_variables) != 2 || !isset($defined_variables['help']) || gettype($defined_variables['help']) != 'array')
		{
			$this->messages->push('fail', $this->user->lang('FILE_INVALID_VARS', $file, 'help'));
			return;
		}

		$column_breaks = 0;
		foreach ($help as $help)
		{
			if (gettype($help) != 'array' || sizeof($help) != 2 || !isset($help[0]) || !isset($help[1]))
			{
				$this->messages->push('fail', $this->user->lang('FILE_HELP_INVALID_ENTRY', $file, htmlspecialchars(serialize($help))));
			}
			else if ($help[0] == '--' && $help[1] == '--')
			{
				$column_breaks++;
			}
		}
		if ($column_breaks != 1)
		{
			$this->messages->push('fail', $this->user->lang('FILE_HELP_ONE_BREAK', $file));
		}
	}

	/**
	* Validates the search_synonyms.php file
	*
	* Files must only contain the variable $synonyms.
	* This variable must be an array of string => string entries.
	*
	* @todo		Check for template vars and html
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_search_synonyms_file($file)
	{
		include($this->validate_language_dir . '/' . $file);

		$defined_variables = get_defined_vars();
		if (sizeof($defined_variables) != 2 || !isset($defined_variables['synonyms']) || gettype($defined_variables['synonyms']) != 'array')
		{
			$this->messages->push('fail', $this->user->lang('FILE_INVALID_VARS', $file, 'synonyms'));
			return;
		}

		foreach ($synonyms as $synonym1 => $synonym2)
		{
			if (gettype($synonym1) != 'string' || gettype($synonym2) != 'string')
			{
				$this->messages->push('fail', $this->user->lang('FILE_SEARCH_INVALID_TYPES', $file, htmlspecialchars(serialize($synonym1)), htmlspecialchars(serialize($synonym2))));
			}
		}
	}

	/**
	* Validates the search_ignore_words.php file
	*
	* Files must only contain the variable $words.
	* This variable must be an array of string entries.
	*
	* @todo		Check for template vars and html
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_search_ignore_words_file($file)
	{
		include($this->validate_language_dir . '/' . $file);

		$defined_variables = get_defined_vars();
		if (sizeof($defined_variables) != 2 || !isset($defined_variables['words']) || gettype($defined_variables['words']) != 'array')
		{
			$this->messages->push('fail', $this->user->lang('FILE_INVALID_VARS', $file, 'words'));
			return;
		}

		foreach ($words as $word)
		{
			if (gettype($word) != 'string')
			{
				$this->messages->push('fail', $this->user->lang('FILE_SEARCH_INVALID_TYPE', $file, htmlspecialchars(serialize($word))));
			}
		}
	}

	/**
	* Validates a index.htm file
	*
	* Only empty index.htm or the default htm file are allowed
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_index_file($file)
	{
		$validate_file = (string) file_get_contents($this->validate_language_dir . '/' . $file);

		// Empty index.htm file or one that displayes an empty white page
		if ($validate_file !== '' && md5($validate_file) != '16703867d439efbd7c373dc2269e25a7')
		{
			$this->messages->push('fail', $this->user->lang('INVALID_INDEX_FILE', $file));
		}
	}

	/**
	* Validates the iso.txt file
	*
	* Should only contain 3 lines:
	* 1. English name of the language
	* 2. Native name of the language
	* 3. Line with information about the author
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_iso_file($file)
	{
		$iso_file = (string) file_get_contents($this->validate_language_dir . '/' . $file);
		$iso_file = explode("\n", $iso_file);

		if (sizeof($iso_file) != 3)
		{
			$this->messages->push('fail', $this->user->lang('INVALID_ISO_FILE', $file));
		}
	}

	/**
	* Validates whether a file checks for the IN_PHPBB constant
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_defined_in_phpbb($file)
	{
		$file_contents = (string) file_get_contents($this->validate_language_dir . '/' . $file);

		// Regex copied from MPV
		if (!preg_match("#defined([ ]+){0,1}\(([ ]+){0,1}'IN_PHPBB'#", $file_contents))
		{
			$this->messages->push('fail', $this->user->lang('FILE_MISSING_IN_PHPBB', $file));
		}
	}

	/**
	* Validates whether a file checks whether the file uses Linux line endings
	*
	* @param	string	$file		File to validate
	* @return	null
	*/
	public function validate_line_endings($file)
	{
		$file_contents = (string) file_get_contents($this->validate_language_dir . '/' . $file);

		if (strpos($file_contents, "\r") !== false)
		{
			$this->messages->push('fail', $this->user->lang('FILE_UNIX_ENDINGS', $file));
		}
	}
}
