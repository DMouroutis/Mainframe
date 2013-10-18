<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Reporting extends CI_Controller
{
	public $pages_changed = array();
	public $versioned_files = array();
	public $list_of_all_controllers = array();
	public $db_changes = array();
	
	/**
	 * The class constructor
	*/
	function __construct()
	{
		parent::__construct();
		$this->load->helper('date');
	}
	
	/**
	 * For user convenience all user inputs are set in this function
	 * @return array
	 */
	function _user_inputs()
	{
		//Date Format dd/mm/yyyy
		//Date seperators can have the format of:  "."  "/"  ","
		$since_date = '';
		$before_date = '';
		
		//For PDF Creation
		$project_author = '';
		$project_title = '';
		
		//Use a string with the link if the pages that are changed
		//have to redirect to another domain
		$site_url = base_url();
		
		//For db_changes only:
		$version_number = '';
		$sql_file_name = '';
		
		//In case sql script is saved elsewhere, change this
		$sql_file_link = './app/controllers/mainframe/'.$sql_file_name;
		
		//We need slash at the end of our url
		if (substr($site_url,-1) !== '/')
		{
			$site_url .= '/';
		}
		
		$data = array(
				'since' => $since_date,
				'before' => $before_date,
				'sql_file_link' => $sql_file_link,
				'project_author' => $project_author,
				'project_title' => $project_title,
				'version_number' => $version_number,
				'site_url' => $site_url			
		);
		
		return $data;
	}
	
	function get_db_changes()
	{
		$data = $this->_user_inputs();
		$since_date = $data['since'];
		$before_date = $data['before'];
		$sql_file_link = $data['sql_file_link'];
		
		// Validating date input
		$since = str_replace('/', '-', $since_date);
		$before = str_replace('/', '-', $before_date);
	
		$timestamp_since = strtotime($since);
		$timestamp_before = strtotime($before);
		if(!$timestamp_since || !$timestamp_before || $timestamp_since >= $timestamp_before || $timestamp_since >= now())
		{
			die();
		}
		else if($timestamp_before > now())
		{
			// $before_date is needed for the pdf, so the date of "now" is stored in it
			$before_date = date('d/m/Y');
		}
	
		$this->_parse_sql_file($sql_file_link);
	
		$this->_create_db_changes_pdf();
	
		die();
	}
	
	function get_git_commits()
	{
		$data = $this->_user_inputs();
		$since_date = $data['since'];
		$before_date = $data['before'];
		
		// Changing the dates to a form that git understands
		$since = implode("-", array_reverse(explode("/", $since_date))) . ' 00:00:00';
		$before = implode("-", array_reverse(explode("/", $before_date))) . ' 23:59:59';
	
		// Validating input
		$timestamp_since = strtotime($since);
		$timestamp_before = strtotime($before);
		if(!$timestamp_since || !$timestamp_before || $timestamp_since >= $timestamp_before || $timestamp_since >= now())
		{
			die();
		}
		else if($timestamp_before > now())
		{
			// If there is no "before" value, git will assume that the before date time is "now"
			$before = NULL;
			// $before_date is needed for the pdf though, so the date of "now" is stored in it
			$before_date = date('d/m/Y');
		}
	
		// Getting the log from git
		$log = htmlspecialchars(shell_exec('git log -p'.($since?' --since="'.$since.'"':'').($before?' --before="'.$before.'"':'').' --pretty=format:"::commit:: %h  %s" --all --date-order --date=iso'));
		if($log)
		{
			// Parsing the log
			$commits = $this->_parse_git_log($log);
			// Reading the array and separating the changes in urls
			foreach($commits as $commit_hash => $files_changed)
			{
				// Removing the "cached" versioned files, because a different commit will have different versioned files
				$this->versioned_files = array();
				foreach($files_changed as $file)
				{
					// If it's a controller, but not ajax
					if($file['type'] == 'controller' && (end(explode('/', $file['path'])) !== 'ajax.php'))
					{
						// For each change in the controller, try to find which function was changed
						foreach($file['changes'] as $change)
						{
							// $page_found becomes TRUE when the url of the page that the change affected, is found
							// in the sample changed lines sent with the git log
							$page_found = FALSE;
							$no_changes = TRUE;
							foreach($change['lines'] as $line)
							{
								// Checking if the line is a change (+ addition or, - deletion) and it's not a blank line
								if((strpos($line, '+') === 0 || (strpos($line, '-') === 0)) && !in_array(trim($line), array('+', '-')))
								{
									$no_changes = FALSE;
								}
								// Checking each line if it includes the word function, so that we can form the page url immediately
								// A check is made so that functions that got removed, are not taken into account
								if(strpos($line, 'function') !== FALSE && strpos($line, '-') !== 0)
								{
									// Retrieving the name of the function from the line if it's really a function
									$function_data = $this->_retrieve_function_data($line);
									if(isset($function_data['name']))
									{
										// Form the url of the page
										$page_found = $this->_form_urls($file['path'], $function_data, $file['path'], $commit_hash, NULL);
										break;
									}
								}
							}
							// If all the lines of the changes are blank (error of git, or the change is actually a clean-up of the
							// empty lines), skip this block of changes
							if($no_changes)
							{
								break;
							}
							// If the function name was not in the changes of the log, retrieve the whole file as it was versioned
							if(!$page_found)
							{
								// If we have cached the versioned file, there is no need to get it from git
								if(!isset($this->versioned_files[$commit_hash][$file['path']]))
								{
									$versioned_file = explode("\n", shell_exec('git show ' . $commit_hash . ':' . $file['path']));
									$this->versioned_files[$commit_hash][$file['path']] = $versioned_file;
								}
								else
								{
									$versioned_file = $this->versioned_files[$commit_hash][$file['path']];
								}
								// Finding the line at which the changes start
								$starting_line = reset(explode(',', end(explode('+', $change['line_numbers']))));
								foreach($change['lines'] as $key => $line)
								{
									// Moving to the first change (+) or (-)
									if(strpos($line, '-') === 0 || strpos($line, '+') === 0)
									{
										$starting_line += $key;
										break;
									}
								}
								$function_data = $this->_find_function_data($starting_line, $versioned_file);
								if($function_data['name'])
								{
									$this->_form_urls($file['path'], $function_data, $file['path'], NULL, $versioned_file);
								}
							}
						}
					}
					else if($file['type'] == 'view')
					{
						$file_path_parts = explode("/", $file['path']);
						$view_path = array();
						// If the view is in a plugin
						if($file_path_parts[1] == 'plugins')
						{
							// Setting the path of all the controllers that may call the view
							$controllers_folder = "/app/plugins/" . $file_path_parts[2] . "/controllers";
						}
						// If the view is not in a plugin
						else
						{
							// Setting the path of all the controllers that may call the view
							$controllers_folder = "/app/controllers";
						}
						// Finding the position the view path has in the array of its filename
						// For example if the path is: app/plugins/resources/views/finland/resource_bookings/resource_booking_save.php
						// we should get: resource_bookings/resource_booking_save
						// Getting the key of the string "views" in the array
						$path_position_in_the_array = array_search('views', $file_path_parts)+1;
						// Keeping only the path of the view
						$view_path = array_slice($file_path_parts, $path_position_in_the_array);
						// If the the path of the view has at least two parts, it means that it's located in a "theme" folder,
						// so the theme folder has to be removed from the path
						if(sizeof($view_path) > 1)
						{
							array_shift($view_path);
						}
						// Forming the path
						$view_path = implode("/", $view_path);
						// Removing the .php from the end of the string
						$view_path = reset(explode('.', $view_path));
	
						// Open the directory of the controllers that belong to the same plugin (or core) with the view that
						// was changed, and get their paths
						$controller_paths = $this->_get_controller_filepaths($controllers_folder);
						foreach($controller_paths as $controller_path)
						{
							// Opening each controller file
							$controller_lines = file($controller_path);
							// Going through every line of each controller and searching for a call of the view file
							foreach($controller_lines as $line_number => $line)
							{
								if(strpos($line, '$this->load->view("' . $view_path . '"') !== FALSE || strpos($line, "\$this->load->view('" . $view_path . "'") !== FALSE)
								{
									$function_data = $this->_find_function_data($line_number+1, $controller_lines);
									if(isset($function_data['name']))
									{
										// Setting the path of the controller relative to the application root and not relative to the hard drive
										$controller_path = explode('app', $controller_path);
										$controller_path =  'app' . $controller_path[1];
										// Getting the url affected by the changes
										$this->_form_urls($controller_path, $function_data, $file['path'], NULL, $controller_lines);
									}
								}
							}
						}
					}
					else if($file['type'] == 'model')
					{
						// For each change in the model, try to find which function was changed
						foreach($file['changes'] as $change)
						{
							// $function_found becomes TRUE when the function name of the of the model that was changed is found
							$function_found = FALSE;
							$no_changes = TRUE;
							foreach($change['lines'] as $line)
							{
								// Checking if the line is a change (+ addition or, - deletion) and it's not a blank line
								if((strpos($line, '-') === 0 || (strpos($line, '+') === 0)) && !in_array(trim($line), array('+', '-')))
								{
									$no_changes = FALSE;
								}
								// Checking each line if it includes the word function, so that we can form the page url immediately
								// A check is made so that functions that got removed, are not taken into account
								if(strpos($line, 'function') !== FALSE && strpos($line, '-') !== 0)
								{
									// Retrieving the name of the function from the line if it's really a function
									$function_data = $this->_retrieve_function_data($line);
									if(isset($function_data['name']))
									{
										// Form the url of the page
										$function_found = TRUE;
										$this->_crawl_for_model_calls($function_data, $file['path']);
										break;
									}
								}
							}
							// If all the lines of the changes are blank (error of git, or the change is actually a clean-up of the
							// empty lines), skip this block of changes
							if($no_changes)
							{
								break;
							}
							// If the function name was not in the changes of the log, retrieve the whole file as it was versioned
							if(!$function_found)
							{
								if(!isset($this->versioned_files[$commit_hash][$file['path']]))
								{
									$versioned_file = explode("\n", shell_exec('git show ' . $commit_hash . ':' . $file['path']));
									$this->versioned_files[$commit_hash][$file['path']] = $versioned_file;
								}
								else
								{
									$versioned_file = $this->versioned_files[$commit_hash][$file['path']];
								}
								// Finding the line at which the changes start
								$starting_line = reset(explode(',', end(explode('+', $change['line_numbers']))));
								foreach($change['lines'] as $key => $line)
								{
									// Moving to the first change (+) or (-)
									if(strpos($line, '-') === 0 || strpos($line, '+') === 0)
									{
										$starting_line += $key;
										break;
									}
								}
								// Getting the function name of the function that changed in the model
								$function_data = $this->_find_function_data($starting_line, $versioned_file);
								if($function_data)
								{
									// Crawling the controllers for calls of the model
									$this->_crawl_for_model_calls($function_data, $file['path']);
								}
							}
						}
					}
				}
			}
			// There is no need to keep the versioned files anymore
			unset($this->versioned_files);
			if($this->pages_changed)
			{
				ksort($this->pages_changed);
				$this->_create_git_pdf();
			}
			die();
		}
	}
	
	/**
	 * Crawls all the controllers of the application in order to find calls of the function
	 * of the model that changed, and generates the urls of the pages it affected
	 *
	 * @param string $function_name
	 * @param string $file_path
	 */
	function _crawl_for_model_calls($function_data, $file_path)
	{
		$model_file_name = reset(explode('.', end(explode('/', $file_path))));
		// Getting a list of all the controller paths of the application
		if(!$this->list_of_all_controllers)
		{
			// Getting the list of controllers that do not belong to any plugins
			$controllers_list = $this->_get_controller_filepaths('/app/controllers');
	
			// Opening the plugins folder to get the names of all the plugins
			if ($handle = opendir($_SERVER['DOCUMENT_ROOT'] . '/app/plugins'))
			{
				while (($entry = readdir($handle)) !== FALSE)
				{
					$files[] = $entry;
				}
				closedir($handle);
				foreach($files as $plugin_name)
				{
					// Skipping the "go back"
					if($plugin_name != '.' && $plugin_name != '..')
					{
						// Opening each plugin folder
						if ($handle = opendir($_SERVER['DOCUMENT_ROOT'] . '/app/plugins/' . $plugin_name))
						{
							while (($entry = readdir($handle)) !== FALSE)
							{
								$plugin_folders[] = $entry;
							}
							closedir($handle);
	
							// Opening the controllers folder of the plugin
							if(in_array('controllers', $plugin_folders))
							{
								$controllers = $this->_get_controller_filepaths('/app/plugins/' . $plugin_name . '/controllers');
	
								foreach($controllers as $controller)
								{
									// Skipping the "go back"
									if($controller != '.' && $controller != '..')
									{
										// Getting the names of the controllers
										$controllers_list[] = $controller;
									}
								}
							}
						}
					}
				}
			}
			$this->list_of_all_controllers = $controllers_list;
		}
		// Opening each controller file
		foreach($this->list_of_all_controllers as $controller_path)
		{
			$controller_file = file($controller_path);
			// Reading each line of the controller
			foreach($controller_file as $line_number => $line)
			{
				// If there is a call of the function of the model that changed
				if(strpos($line, '$this->' . $model_file_name . '->' . $function_data['name']) !== FALSE)
				{
					// Find the function of the controller in which this call is done
					$controller_function_data = $this->_find_function_data($line_number+1, $controller_file);
					if($controller_function_data)
					{
						// Setting the path of the controller relative to the application root and not relative to the hard drive
						$controller_path = explode('app', $controller_path);
						$controller_path = 'app' . $controller_path[1];
						$this->_form_urls($controller_path, $controller_function_data, $file_path, NULL, $controller_file);
					}
				}
			}
		}
	}
	
	/**
	 * Given a line that includes the word "function" in it, it returns the function name, if there is one
	 *
	 * @param string $line
	 * @return string $function_name
	 */
	function _retrieve_function_data($line)
	{
		$line_pieces = explode(' ', $line);
		$function_data = array();
		foreach($line_pieces as $key => $line_piece)
		{
			if(trim($line_piece) == 'function')
			{
				// If the function is of this type: function function_name($params)
				if(isset($line_pieces[$key+1]) && strpos($line_pieces[$key+1], '(') !== FALSE)
				{
					$function_data['name'] = reset(explode('(', $line_pieces[$key+1]));
				}
				// If the function is of this type: function function_name ($params)
				else if(isset($line_pieces[$key+2]) && strpos($line_pieces[$key+2], '(') === 0)
				{
					$function_data['name'] = $line_pieces[$key+1];
				}
				// If the name was retrieved
				if(isset($function_data['name']))
				{
					// Get the parameters
					$parameters = explode(',', reset(explode(')', end(explode('(', $line)))));
					foreach($parameters as $parameter)
					{
						$function_data['parameters'][] = trim(reset(explode('=', $parameter)));
					}
					break;
				}
			}
		}
		return $function_data;
	}
	
	/**
	 * Given a file, as an array of its lines, and a line number, this function returns the function in which the given line belongs.
	 * It starts from the given line in the file and searches each line upwards, searching for the function name.
	 *
	 * @param int $starting_line
	 * @param array $file
	 */
	function _find_function_data($starting_line, $file)
	{
		// Since an array will be iterated, the starting line should be $starting_line-1
		$starting_line--;
	
		$opening_brackets_number = 0;
		$closing_brackets_number = 0;
	
		for($i = $starting_line; $i >= 0; $i--)
		{
			// Counting the number of the opening and closing brackets of each line.
			$opening_brackets_number += substr_count($file[$i], '{');
			$closing_brackets_number += substr_count($file[$i], '}');

			// If in the line, there is the word "function", and the number of opening brackets encountered up to this line is
			// different from the number of the closing brackets (which means that the starting line was inside this function)
			// check if it's a function declaration, and if it is, retrieve the funciton data (the name and the variables)
			if(strpos($file[$i], 'function') !== FALSE)
			{
				$function_data = $this->_retrieve_function_data($file[$i]);
				// The occurance of the "function" string is accompanied by a function name and
				// the starting line of the git changes is in the function
				if(isset($function_data['name']) && $function_data['name'] && ($opening_brackets_number != $closing_brackets_number))
				{
					// Return the function data if the function has a name
					return $function_data;
				}
				// The starting line of the git changes is not in a function
				else if(isset($function_data['name']) && $function_data['name'] && ($opening_brackets_number == $closing_brackets_number))
				{
					return NULL;
				}
			}
		}
		// Reached the top of the file without finding any functions
		return NULL;
	}
	
    /**
 	 * Given a controller function name and the name of the file it belongs, it returns the url of the page that function affects
	 *
	 * @param string $controller_path 		(The path where the controller is located)
	 * @param string $function_name 		(The function name in the controller which calls the affecting file)
	 * @param string $changed_file_path 	(The path of the file that has git changes, and that gets called by a function of the controller)
	 * @param string $commit_hash 			(The hash of the commit that the changes happened. This should be provided in case the whole controller file isn't)
	 * @param array $controller_file 		(The whole controller file. If this is provided, there is no need to provide the hash of the commit)
	 * @param int $recursive_loop 			(This integer counts the number of recursions of the function. It won't allow the function to call itself more than 3 times)
	 * @return boolean
	 */
	function _form_urls($controller_path, $controller_function, $changed_file_path, $commit_hash = NULL, $controller_file = NULL, $recursive_loop = 0)
	{
		// If the function corresponds to a url and does not have the form of _function_name
		if(strpos($controller_function['name'], '_') !== 0)
		{
			// Form the url of the page
			$filename_parts = explode('/', $controller_path);
			if($filename_parts[1] == 'plugins')
			{
				$path = $filename_parts[2] . '/' . reset(explode('.', $filename_parts[4])) . '/' . $controller_function['name'];
			}
			else
			{
				$path = reset(explode('.', $filename_parts[2])) . '/' . $controller_function['name'];
			}
			if(isset($controller_function['parameters']) && $controller_function['parameters'][0])
			{
				foreach($controller_function['parameters'] as $parameter)
				{
					$path .= '/' . $parameter;
				}
			}
			// If the url doesn't exist in the array or it exists, but the file path of the file changed doesn't, insert the file name in the array
			if(!isset($this->pages_changed[$path]) || (isset($this->pages_changed[$path]) && !in_array($changed_file_path, $this->pages_changed[$path])))
			{
				$this->pages_changed[$path][] = $changed_file_path;
			}
			return TRUE;
		}
		// If the function doesn't correspond to a url, find which functions it affects
		else
		{
			if($recursive_loop > 3)
			{
				return FALSE;
			}
			else if($controller_file)
			{
				// Crawl the same controller in order to find calls of this function ($this->_function_name)
				$functions = $this->_get_functions_that_call_undescore_function($controller_path, $controller_function, NULL, $controller_file);
				foreach($functions as $fn)
				{
					// Recursively, form a url for each of the calls
					return $this->_form_urls($controller_path, $fn, $changed_file_path, NULL, $controller_file, ++$recursive_loop);
				}
			}
			else
			{
				$functions = $this->_get_functions_that_call_undescore_function($controller_path, $controller_function, $commit_hash);
				foreach($functions as $fn)
				{
					// Recursively, form a url for each of the calls
					return $this->_form_urls($controller_path, $fn, $changed_file_path, $commit_hash, NULL, ++$recursive_loop);
				}
			}
		}
		return FALSE;
	}
	
	/**
	 * Given a function name in the form of "_function_name", it returns all the functions that call it like "$this->_function_name"
	 *
	 * @param string $controller_path	(The path of the controller that includes the function that begins with an underscore.)
	 * @param string $function_name		(The function name that begins with an underscore. The function searches for its calls in the controller.)
	 * @param string $commit_hash		(The hash of the commit in which these changes happened. This is needed if the whole controller file is not given.)
	 * @param array $controller_file	(The whole controller file, broken down per line in an array. If this is not NULL, the commit hash is not needed.)
	 * @return array $function_data
	 */
	function _get_functions_that_call_undescore_function($controller_path, $controller_function, $commit_hash = NULL, $controller_file = array())
	{
		// If the controller file is not given, get it from git
		if(!$controller_file)
		{
			if(!isset($this->versioned_files[$commit_hash][$controller_path]))
			{
				$controller_file = explode("\n", shell_exec('git show ' . $commit_hash . ':' . $controller_path));
				$this->versioned_files[$commit_hash][$controller_path] = $controller_file;
			}
			else
			{
				$controller_file = $this->versioned_files[$commit_hash][$controller_path];
			}
		}
	
		$function_data = array();
		foreach($controller_file as $key => $controller_line)
		{
			if(strpos($controller_line, "\$this->" . $controller_function['name']) !== FALSE)
			{
				$fn_data = $this->_find_function_data($key+1, $controller_file);
				if($fn_data)
				{
					$function_data[] = $fn_data;
				}
			}
		}
		return $function_data;
	}
	
	/**
	 * Given a folder that contains controller files, it crawls the folder and the subfolders
	 * and returns all the filenames of the controllers in these folders
	 *
	 * @param string $controllers_folder (The file path to the folder.)
	 * @return array $files
	 */
	function _get_controller_filepaths($controllers_folder)
	{
		if ($handle = opendir($_SERVER['DOCUMENT_ROOT'] . $controllers_folder))
		{
			while (($entry = readdir($handle)) !== FALSE)
			{
				$files[] = $entry;
			}
			closedir($handle);
		
			foreach($files as $key => $file)
			{
				$file_parts = explode(".", $file);
				// Removing the "go back" and the ajax files
				if($file == "." || $file == ".." || $file == "ajax.php" || (isset($file_parts[1]) && $file_parts[1] != 'php'))
				{
					unset($files[$key]);
				}
				// If the file is not actually a file, but a folder, open it
				else if(!isset($file_parts[1]))
				{
					$sub_folder_files = $this->_get_controller_filepaths($controllers_folder . "/" . $file);
					foreach($sub_folder_files as $sub_folder_file)
					{
						$files[] = $sub_folder_file;
					}
					unset($files[$key]);
				}
				// If it's a file, insert its path in the $files array
				else
				{
					$files[$key] = $_SERVER['DOCUMENT_ROOT'] . $controllers_folder . "/" . $file;
				}
			}
			return $files;
		}
		else
		{
			return array();
		}
	}

	/**
	 * Gets the log output from git and returns the parsed output as an array. The returned array includes array/trees in the form of:
	 *
	 *				   								     ,----['path'] = (e.g. "app/plugins/reports/controllers/test2.php")
	 *                                                  /
	 * [$commit_hash]----[$indexes_of_files_changed]---{------['type'] = (e.g. "controller")
	 *                                                  \                                              ,----['line_numbers'] = (e.g. "-1118,35 +1118,55)"
	 *                 								     `----['changes']----[$indexes_of_changes]----{
	 *																							       `----['lines']----------[$indexes_of_lines] = (e.g. "+    $result = $ci->db->get('rooms_rooms');")
	 * @param string $log
	 * @return array $commits
	 */
	function _parse_git_log($log)
	{
		// Splitting the commit file in lines
		$commit_lines = explode("\n", $log);
		$lines_index = -2;
		$commits = array();
	
		// Parsing the commit log and inserting the data we need in an array
		foreach($commit_lines as $key => $line)
		{
			// If the line includes a commit, start a new branch in the $files array
			if(strpos($line, '::commit::') !== FALSE)
			{
				if(strpos($line, 'Merge branch') === FALSE)
				{
					$commit_hash = next(explode(" ", $line));
					$commits[$commit_hash] = array();
					$files_index = -1;
				}
			}
			// If the line indicates which files where changed, store it in its commit array
			if(strpos($line, 'diff --git') === 0)
			{
				$line_pieces = explode(" ", $line);
				$file_path = substr($line_pieces[2], 2);
				// Setting the file name
				$commits[$commit_hash][++$files_index]['path'] = $file_path;
				$file_pieces = explode("/", $file_path);
				// Setting the file type
				if(isset($file_pieces[1]))
				{
					if($file_pieces[1] == 'controllers' || ($file_pieces[1] == 'plugins' && $file_pieces[3] == 'controllers'))
					{
						$commits[$commit_hash][$files_index]['type'] = 'controller';
					}
					else if($file_pieces[1] == 'views' || ($file_pieces[1] == 'plugins' && $file_pieces[3] == 'views'))
					{
						$commits[$commit_hash][$files_index]['type'] = 'view';
					}
					else if($file_pieces[1] == 'models' || ($file_pieces[1] == 'plugins' && $file_pieces[3] == 'models'))
					{
						$commits[$commit_hash][$files_index]['type'] = 'model';
					}
					else if($file_pieces[1] == 'config' || ($file_pieces[1] == 'plugins' && $file_pieces[3] == 'config'))
					{
						$commits[$commit_hash][$files_index]['type'] = 'config';
					}
					else if($file_pieces[1] == 'plugins' && $file_pieces[3] == 'assets')
					{
						$commits[$commit_hash][$files_index]['type'] = 'asset';
					}
					else if($file_pieces[1] == 'helpers' || ($file_pieces[1] == 'plugins' && $file_pieces[3] == 'helpers') || (isset($file_pieces[2]) && $file_pieces[0] == 'libs' && $file_pieces[2] == 'helpers'))
					{
						$commits[$commit_hash][$files_index]['type'] = 'helper';
					}
					else if(($file_pieces[1] == 'plugins' && $file_pieces[3] == 'libraries') || $file_pieces[1] == 'libraries' || ($file_pieces[0] == 'libs' && ($file_pieces[2] == 'libraries' || $file_pieces[2] == 'core')))
					{
						$commits[$commit_hash][$files_index]['type'] = 'library';
					}
					else if($file_pieces[0] == 'themes' && $file_pieces[1] == 'finland')
					{
					$commits[$commit_hash][$files_index]['type'] = 'theme';
					}
					else if(isset($file_pieces[2]) && $file_pieces[2] == 'install' && $file_pieces[3] == 'files')
					{
						$commits[$commit_hash][$files_index]['type'] = 'installation_files';
					}
					else if($file_pieces[1] == 'core')
					{
						$commits[$commit_hash][$files_index]['type'] = 'core';
					}
					else
					{
						$commits[$commit_hash][$files_index]['type'] = 'other';
					}
				}
				else
				{
					$commits[$commit_hash][$files_index]['type'] = 'other';
				}
				$changes_index = -1;
			}
			// If the line indicates which lines of the file changed, store it in the line_numbers array
			if(strpos($line, '@@') === 0)
			{
				$commits[$commit_hash][$files_index]['changes'][++$changes_index]['line_numbers'] = trim(next(explode('@@', $line)));
				$commits[$commit_hash][$files_index]['changes'][$changes_index]['lines'] = array();
				$lines_index = -1;
			}
			else
			{
				if($lines_index >= -1 && (strpos($line, '+') === 0 || strpos($line, '-') === 0 || strpos($line, ' ') === 0 ))
				{
					$commits[$commit_hash][$files_index]['changes'][$changes_index]['lines'][++$lines_index] = $line;
				}
				else
				{
					$lines_index = -2;
				}
			}
		}
		return $commits;
	}
	
	/**
	* Gets the pages_changed array, and puts it in a pdf, by converting it to html first
	*/
	function _create_git_pdf()
	{
		$data = $this->_user_inputs();
		$since_date = $data['since'];
		$before_date = $data['before'];
		$project_title = $data['project_title'];
		$project_author = $data['project_author'];
		$version_number = $data['version_number'];
		$site_url = $data['site_url'];
		
		// Include the main TCPDF library (search for installation path).
		require_once($_SERVER['DOCUMENT_ROOT'] . '/libs/php/tcpdf/tcpdf.php');
		require_once($_SERVER['DOCUMENT_ROOT'] . '/libs/php/tcpdf/mypdf.php');
		
		// create new PDF document
		$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

		$html = '<style type="text/css">
					div {word-break:break-all;}
					h1 {color: #1e5b6d; line-height: 1;}
					h4 {line-height: 2.5;}
					a {color: black;}
					p {line-height: 0; font-size: 16px;}
					p.file {color: #7f7f7f; font-size: 14px;}
				 </style>';
		$html .= '<div>';
		$html .= '<h1>' . $project_title . ', List of changed screens</h1>';
		$html .= '<h2>Version: ' . $version_number . '</h2><p></p>';
		$html .= '<p>Covering <b>' . $since_date . ' to ' . $before_date . '</b></p>';
		$html .= '<p>Delivered by ' . $project_author . ' on ' . date("d/m/Y") . '</p><p></p><p></p>';
		
		foreach($this->pages_changed as $url => $files)
		{
			$html .= '<h4><a href="' . $site_url . $url . '">' . $url . '</a></h4>';
			foreach($files as $file)	
			{
				$html .= '<p class="file">/' . $file . '</p>';
			}
			$html .= '<p></p>';
		}
		$html .= '</div>';
	
		// set document information
		$pdf->SetCreator(PDF_CREATOR);
		$pdf->SetAuthor($project_author);
		$pdf->SetTitle($project_title);
		$pdf->SetSubject('List of changed screens by' . $project_author);
		$pdf->SetKeywords($project_title . ', changes, ' . date("d-m-Y"));
	
		$pdf->setPrintHeader(FALSE);
	
		// set default monospaced font
		$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
	
		// set margins
		$pdf->SetMargins(20, 20, 20);
	
		// set auto page breaks
		$pdf->SetAutoPageBreak(TRUE, 24);
	
		// set image scale factor
		$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
	
		// set default font subsetting mode
		$pdf->setFontSubsetting(true);
	
		// Set font
		$pdf->SetFont('opensans', '', 14, '', true);
	
		// Add a page
		$pdf->AddPage();
	
		// Print text using writeHTMLCell()
		$pdf->writeHTMLCell(0, 0, '', '', $html, 0, 1, 0, true, '', true);
		//$pdf->writeHTML($html, 0, 0, true, '', true);
	
		$project_title = strtolower($project_title);
		$project_title = str_replace(" ","_",$project_title);
		
		// Close and output PDF document
		$pdf->Output(date("Y-m-d") .'_' . $project_title . '_changed_screens.pdf', 'I');
	}
	
	/**
	 * Reads SQL file and stores the changes in the public array db_changes
	 */
	function _parse_sql_file($sql_file_link)
	{
		//We read the sql code with the db alterations
		if (!$file = @file_get_contents($sql_file_link))
		{
			echo '<h2> Failure with file reading </h2>';
			die();
		}
	
		//We convert the string into array where each line is an SQL command
		$file = explode ('GO',$file);
		$file = array_map('trim', $file);
	
		foreach ($file as $key => $line)
		{
			//any DROP table commands insert into [tables_dropped]
			if (strpos($line,'DROP') === 0)
			{
				$this->db_changes['tables_dropped'][] = substr($line,strrpos($line, '[')+1,-1);
			}
			//ALTER table command
			else if (strpos($line,'ALTER') === 0)
			{
				$edited_row = substr($line,strpos($line, '[dbo].[')+6);
	
				$table_name = substr($edited_row,strpos($edited_row, '[')+1,strpos($edited_row, ']')-1);
				
				//We want to include ADD command but not for ADD CONSTRAINT.
				if ((strpos($edited_row,'ADD') !== FALSE) && (strpos($edited_row,'CONSTRAINT') == FALSE))
				{
					$case_add = substr($edited_row,strpos($edited_row, 'ADD')+4);
					$case_add = explode(',',$case_add);
					$case_add = array_map('trim', $case_add);
					
					$add_column = array();
					foreach ($case_add as $add_field)
					{
						if(strpos($add_field, '[') === 0)
						{
							$add_field = explode(']',$add_field);
							$add_column[] = substr($add_field[0],strpos($add_field[0], '[')+1);
						}
					}
					$this->db_changes['tables_altered'][$table_name]['type']['add_column'] = $add_column;				
				}
				else if (strpos($edited_row,'DROP COLUMN') !== FALSE)
				{
					$case_drop = substr($edited_row,strpos($edited_row, 'DROP COLUMN'));
					$case_drop = explode(']',$case_drop);
					
					//We reset our array everytime
					$drop_column = array();
					
					foreach ($case_drop as $drop_field)
					{
						//DROP COLUMN command may have multiple values dropped in the same line
						if(strrpos($drop_field, '['))
						{
							$drop_column[] = substr($drop_field,strrpos($drop_field, '[')+1);
						}
					}
					$this->db_changes['tables_altered'][$table_name]['type']['drop_column'] = $drop_column;
				}
				else if (strpos($edited_row,'ALTER COLUMN') !== FALSE)
				{
					$case_alter = substr($edited_row,strpos($edited_row, 'ALTER COLUMN'));
					$case_alter = explode(']',$case_alter);
					
					$alter_column = substr($case_alter[0],strpos($case_alter[0], '[')+1);
					$this->db_changes['tables_altered'][$table_name]['type']['alter_column'][] = $alter_column;
				}
			}
			//CREATE new table command
			else if (strpos($line,'CREATE') === 0)
			{
				$edited_row = substr($line,strpos($line, '[dbo].[')+6);
				$table_name = substr($edited_row,strpos($edited_row, '[')+1,strpos($edited_row, ']')-1);
	
				$edited_row = substr($edited_row,strpos($edited_row, '(')+1);
				$table_fields = explode(',',$edited_row);
				$table_fields = array_map('trim', $table_fields);
	
				foreach($table_fields as $field)
				{
					if (strpos($field,'[') === 0)
					{
						$add_field = substr($field,strpos($field, '[')+1,strpos($field, ']')-1);
	
						$this->db_changes['tables_created'][$table_name][] = $add_field;
					}
				}
			}
			//EXEC commands
			else if (strpos($line,'EXEC') === 0)
			{
				if (strpos($line,'sp_rename') !== FALSE)
				{
					$edited_row = explode(',',$line);
					$old_name = substr($edited_row[0],strrpos($edited_row[0], '[')+1,-2);
					$new_name = substr($edited_row[1],strpos($edited_row[1], "'")+1,-1);
						
					$this->db_changes['renames'][$old_name] = $new_name;
				}
			}
		}
	}
	
	/**
	 * Gets the db_changes array, and puts it in a pdf, by converting it to html first
	 */
	function _create_db_changes_pdf()
	{
		$data = $this->_user_inputs();
		$since_date = $data['since'];
		$before_date = $data['before'];
		$project_title = $data['project_title'];
		$project_author = $data['project_author'];
		$version_number = $data['version_number'];
		
		// Include the main TCPDF library (search for installation path).
		require_once($_SERVER['DOCUMENT_ROOT'] . '/libs/php/tcpdf/tcpdf.php');
		require_once($_SERVER['DOCUMENT_ROOT'] . '/libs/php/tcpdf/mypdf.php');
	
		// create new PDF document
		$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
	
		$html = '<style type="text/css">
					div {word-break:break-all;}
   					h1 {color: #1e5b6d; line-height: 1;}
					h2 {color: #1e5b6d; line-height: 0;}
					p {line-height: 0; font-size: 16px; }
				 </style>';
		$html .= '<div>';		
		$html .= '<h1>' . $project_title . ', DB Changes Report</h1>';
		$html .= '<h2>Version: ' . $version_number . '</h2><p></p>';
		$html .= '<p>Covering <b>' . $since_date . ' to ' . $before_date . '</b></p>';
		$html .= '<p>Delivered by ' . $project_author . ' on ' . date("d/m/Y") . '</p><p></p>';
	
		if (isset($this->db_changes['tables_dropped']))
		{
			$html .= '<p></p>';
			$html .= '<h3>Tables discarded:</h3>';
			foreach ($this->db_changes['tables_dropped'] as $table_name)
			{
				$html .= '<p><u>' . $table_name . '</u></p>';
			}
		}
		if (isset($this->db_changes['renames']))
		{
			$html .= '<p></p>';
			$html .= '<h3>The following have been renamed:</h3>';
			foreach ($this->db_changes['renames'] as $old_name => $new_name)
			{
				$html .= '<p>' . $old_name . ' => ' . $new_name . '</p>';
			}
		}
		if (isset($this->db_changes['tables_altered']))
		{
			$html .= '<p></p>';
			$html .= '<h3>Tables altered:</h3>';
				
			foreach ($this->db_changes['tables_altered'] as $table_name => $table)
			{
				$html .= '<p>Table <u>' . $table_name . ':</u></p>';
	
				foreach ($table as $changes)
				{
					foreach ($changes as $type => $column)
					{
						foreach ($column as $column_name)
						{
							switch ($type) {
								case "drop_column":
									$html .= '<p>Dropped Column   ' . $column_name . '</p>';
									break;
								case "add_column":
									$html .= '<p>Added Column   ' . $column_name . '</p>';
									break;
								case "alter_column":
									$html .= '<p>Altered Column   ' . $column_name . '</p>';
									break;
							}
						}								
					}
					$html .= '<p></p>';
				}
			}
		}
		if (isset($this->db_changes['tables_created']))
		{
			$html .= '<h3>Tables created:</h3>';
	
			foreach ($this->db_changes['tables_created'] as $table_name => $table)
			{
				$html .= '<p><u>' . $table_name . '</u> with fields:</p>';
	
				foreach ($table as $field)
				{
					$html .= '<p>' . $field . '</p>';
				}
				$html .= '<p></p>';
			}
		}
		$html .= '</div>';
	
		// set document information
		$pdf->SetCreator(PDF_CREATOR);
		$pdf->SetAuthor($project_author);
		$pdf->SetTitle($project_title);
		$pdf->SetSubject('List of database changes by' . $project_author);
		$pdf->SetKeywords($project_title . ', changes, ' . date("d-m-Y"));
	
		$pdf->setPrintHeader(FALSE);
	
		// set default monospaced font
		$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
	
		// set margins
		$pdf->SetMargins(20, 20, 20);
	
		// set auto page breaks
		$pdf->SetAutoPageBreak(TRUE, 24);
	
		// set image scale factor
		$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
	
		// set default font subsetting mode
		$pdf->setFontSubsetting(true);
	
		// Set font
		$pdf->SetFont('opensans', '', 14, '', true);
	
		// Add a page
		$pdf->AddPage();
	
		// Print text using writeHTMLCell()
		$pdf->writeHTMLCell(0, 0, '', '', $html, 0, 1, 0, true, '', true);
		//$pdf->writeHTML($html, 0, 0, true, '', true);
	
		$project_title = strtolower($project_title);
		$project_title = str_replace(" ","_",$project_title);
		
		// Close and output PDF document
		$pdf->Output(date("Y-m-d") .'_' . $project_title . '_changed_db.pdf', 'I');
	}
}