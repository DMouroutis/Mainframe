<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/*
 * Every little thing that doesn't fit in any other helper
 */


/*
 * Checks if a link should have the class 'active'.
 * It does so by checking if the uri starts with the $segment_value
 * This is useful mostly in menus where we want to have the active item highlighted.
 * 
 * For example to check if the uri starts with 'arrangement' do a active_class('arrangement')
 * This will return 'active' if it's true  
 * 
 * @param		string		$segment_value		The start of the URL you want to check against
 * @param		bool		$return				If set, the function returns the class instead of echoing it
 * @returns		string							Echoes 'active' on success, returns false on failure
 */
function active_class($segment_value, $return = FALSE){
	$ci =& get_instance();

	if (strpos($ci->uri->uri_string(), $segment_value) === 0)
	{
		if($return)
		{
			return 'active';
		}
		echo 'active';
	}
	else {
		return false;
	}
}

 


/*
 * Returns a config item
*/
function config($item){
	$ci =& get_instance();
	return $ci->config->item($item);
}


/*
 * Returns a session item
*/
function session($item){
	$ci =& get_instance();
	return $ci->session->userdata($item);
}


function validate($rules = array()){
	$ci =& get_instance();

	// If there are no rules set for the validation then just check
	// if there was a form submited or not
	if (count($rules) == 0){
		if (count($_POST) > 0){
			return TRUE;
		}
		else {
			return FALSE;
		}
	}

	$ci->load->library('form_validation');

	foreach ($rules as $rule){
		$ci->form_validation->set_rules($rule, $rule, 'required');
	}

	return $ci->form_validation->run();
}


function save_form($table, $where = array(), $datefields = array()){
	$ci =& get_instance();
	$db_data = array();

	foreach ($_POST as $field => $value){
		if ($field != 'submit'){
			if (in_array($field, $datefields)){
				$db_data[$field] = input_gr_to_mysql($value);
			}
			else {
				$db_data[$field] = $value;
			}
		}
	}

	if (count($where) > 0){
		$ci->db->where($where);
		$ci->db->update($table, $db_data);
	}
	else {
		$ci->db->insert($table, $db_data);
	}
}


function json($response = array()){
	echo json_encode($response);
	die();
}


function google_analytics(){
	$ci = &get_instance();
	if (((base_url() === 'http://deskhot.com/') || (base_url() === 'http://m.deskhot.com/'))
			&& ($ci->session->userdata('username') != 'bibakisv') && ($ci->session->userdata('username') != 'vskandalakis')){
		$output = '
		<script type="text/javascript">

		var _gaq = _gaq || [];
		_gaq.push([\'_setAccount\', \'UA-8249283-14\']);
		_gaq.push([\'_setDomainName\', \'.deskhot.com\']);
		_gaq.push([\'_trackPageview\']);

		(function() {
		var ga = document.createElement(\'script\'); ga.type = \'text/javascript\'; ga.async = true;
		ga.src = (\'https:\' == document.location.protocol ? \'https://ssl\' : \'http://www\') + \'.google-analytics.com/ga.js\';
		var s = document.getElementsByTagName(\'script\')[0]; s.parentNode.insertBefore(ga, s);
	})();

	</script>
	';
		return $output;
	}
}


// returns a suffix for css and js files to avoid caching. Example: ?v=15
function cjsuf(){
	$ci = & get_instance();
	$cjsuf = '?v='.$ci->config->item('cjsuf');
	return $cjsuf;
}