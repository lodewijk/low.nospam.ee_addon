<?php
/*
=====================================================
 This extension was created by Lodewijk Schutte
 - freelance@loweblog.com
 - http://loweblog.com/freelance/
=====================================================
 File: ext.low_no_spam_check.php
-----------------------------------------------------
 Purpose: Checks comment for spam using mod.low_nospam.php
=====================================================
*/

if ( ! defined('EXT'))
{
	exit('Invalid file request');
}

class Low_nospam_check
{
	var $settings		= array();

	var $name			= 'Low NoSpam';
	var $version		= '1.1.0';
	var $description	= 'Anti-spam utility using online services like Akismet and TypePad AntiSpam';
	var $settings_exist = 'y';
	var $docs_url		= '';
	
	var $default_groups	= array(2, 3, 4);
	var $input			= array();
	var $API;


	// -------------------------------
	// Constructor
	// -------------------------------
	function Low_nospam_check($settings='')
	{
		global $LANG;
		
		// $LANG->fetch_language_file('low_nospam');
		
		$this->settings = $settings;
	}
	// END Low_nospam_check


	
	// --------------------------------
	//	Settings
	// --------------------------------	
	function settings()
	{
		global $DB;
		
		// Get member groups
		$groups = array();
		$query = $DB->query("SELECT group_id, group_title FROM exp_member_groups ORDER BY group_title ASC");
		foreach($query->result AS $row)
		{
			$groups[$row['group_id']] = $row['group_title'];
		}
		
		$settings = array(
			'service'					=> array('s', array('akismet' => "akismet", 'tpas' => "tpas"), 'akismet'),
			'api_key'					=> '',
			'check_members'				=> array('ms', $groups, $this->default_groups),
			'check_comments'			=> array('r', array('y' => "yes", 'n' => "no"), 'y'),
			'check_gallery_comments'	=> array('r', array('y' => "yes", 'n' => "no"), 'y'),
			'check_forum_posts'			=> array('r', array('y' => "yes", 'n' => "no"), 'n'),
			'check_wiki_articles'		=> array('r', array('y' => "yes", 'n' => "no"), 'n'),
			'check_trackbacks'			=> array('r', array('y' => "yes", 'n' => "no"), 'y'),
			'check_member_registrations'=> array('r', array('y' => "yes", 'n' => "no"), 'n'),
			'check_freeform_entries'	=> array('r', array('y' => "yes", 'n' => "no"), 'n'),
			'check_ss_user_register'	=> array('r', array('y' => "yes", 'n' => "no"), 'n'),
			'moderate_if_unreachable'	=> array('r', array('y' => "yes", 'n' => "no"), 'y')
		);
		
		return $settings;
	}
	// END settings



	// --------------------------------
	//	Check comment (method called by extension)
	// --------------------------------		 
	function check_comment($data)
	{
		global $SESS, $DB;
		
		// check settings to see if comment needs to be verified
		if ($this->settings['check_comments'] == 'y' AND $this->_check_user())
		{
			// Input array
			$this->input = array(
				'comment_author'		=> $data['name'],
				'comment_author_email'	=> $data['email'],
				'comment_author_url'	=> $data['url'],
				'comment_content'		=> $data['comment']
			);

			// Check it!
			if ($this->is_spam())
			{
				// set comment status to 'c' 
				$data['status'] = 'c';
				
				// insert closed comment to DB
				$DB->query($DB->insert_string('exp_comments', $data));
				
				// Exit
				$this->abort();
			}
		}
		
		// return data as if nothing happened...
		return $data;
	}
	// END check_comment
	
		
	 
	// --------------------------------
	//	Check trackback (method called by extension)
	// --------------------------------		 
	function check_trackback($data)
	{
		// check settings to see if trackback needs to be verified
		if ($this->settings['check_trackbacks'] == 'y' AND $this->_check_user())
		{
			// input array
			$this->input = array(
				'user_ip'				=> $data['trackback_ip'],
				'comment_type'			=> 'trackback',
				'comment_author'		=> str_replace('&#45;','-',$data['weblog_name']),
				'comment_author_email'	=> '',
				'comment_author_url'	=> $data['trackback_url'],
				'comment_content'		=> $data['title']."\n\n".$data['content']
			);

			// Check it!
			if ($this->is_spam())
			{
				// send xml response and exit script
				header('Content-Type: application/xml');
				die("<?xml version=\"1.0\" encoding=\"utf-8\"?".">\n<response>\n<error>1</error>\n<message>Trackback considered spam</message>\n</response>");
			}
		}
		
		// return data as if nothing happened...
		return $data;
	}
	// END check_trackback
	
	
	
	// --------------------------------
	//	Check forum post (method called by extension)
	// --------------------------------		 
	function check_forum_post($obj)
	{
		global $SESS, $IN;

		// check settings to see if trackback needs to be verified
		if ($this->settings['check_forum_posts'] == 'y' AND $this->_check_user())
		{
			// input array
			$this->input = array(
				'user_ip'				=> $SESS->userdata['ip_address'],
				'user_agent'			=> $SESS->userdata['user_agent'],
				'comment_author'		=> (strlen($SESS->userdata['screen_name']) ? $SESS->userdata['screen_name'] : $SESS->userdata['username']),
				'comment_author_email'	=> $SESS->userdata['email'],
				'comment_author_url'	=> $SESS->userdata['url'],
				'comment_content'		=> ($IN->GBL('title') ? $IN->GBL('title')."\n\n" : '').$IN->GBL('body')
			);

			// Check it!
			if ($this->is_spam())
			{
				// No forum post moderation, so just exit
				$this->abort(TRUE);
			}
		}

		// hook doesn't return anything
	}
	// END check_forum_post
	
	
	
	// --------------------------------
	//	Check gallery comment (method called by extension)
	// --------------------------------		 
	function check_gallery_comment()
	{
		global $SESS, $IN, $DB, $LOC;
		
		// check settings to see if comment needs to be verified
		if ($this->settings['check_gallery_comments'] == 'y' AND $this->_check_user())
		{
			// Input array
			$this->input = array(
				'comment_author'		=> $SESS->userdata['screen_name']	? $SESS->userdata['screen_name']	: $IN->GBL('name'),
				'comment_author_email'	=> $SESS->userdata['email']			? $SESS->userdata['email']			: $IN->GBL('email'),
				'comment_author_url'	=> $SESS->userdata['url']			? $SESS->userdata['url']			: $IN->GBL('url'),
				'comment_content'		=> $IN->GBL('comment')
			);
			
			// Check it!
			if ($this->is_spam())
			{
				// gallery entry id
				$entry_id = $DB->escape_str($IN->GBL('entry_id'));
				
				// get gallery id
				$query = $DB->query("SELECT gallery_id FROM exp_gallery_entries WHERE entry_id = '{$entry_id}'");
				
				// row to insert
				$data = array(
					'entry_id'		=> $entry_id,
					'gallery_id'	=> $query->row['gallery_id'],
					'author_id'		=> $SESS->userdata['member_id'],
					'name'			=> $this->input['comment_author'],
					'email'			=> $this->input['comment_author_email'],
					'url'			=> $this->input['comment_author_url'],
					'location'		=> $SESS->userdata['location'] ? $SESS->userdata['location'] : $IN->GBL('location'),
					'ip_address'	=> $SESS->userdata['ip_address'],
					'comment_date'	=> $LOC->now,
					'comment'		=> $this->input['comment_content'],
					'notify'		=> $IN->GBL('notify_me') ? 'y' : 'n',
					// Set status to closed
					'status'		=> 'c'
				);
				
				// insert closed comment to DB
				$DB->query($DB->insert_string('exp_gallery_comments', $data));
				
				// Exit
				$this->abort();
			}
		}
		
		// hook doesn't return anything
	}
	// END check_gallery_comment
	
	
	
	// --------------------------------
	//	Check wiki article (method called by extension)
	// --------------------------------		 
	function check_wiki_article($obj, $query)
	{
		global $SESS, $IN, $DB;
		
		// check settings to see if comment needs to be verified
		if ($this->settings['check_wiki_articles'] == 'y' AND $this->_check_user())
		{
			$this->input = array(
				'user_ip'				=> $SESS->userdata['ip_address'],
				'user_agent'			=> $SESS->userdata['user_agent'],
				'comment_author'		=> (strlen($SESS->userdata['screen_name']) ? $SESS->userdata['screen_name'] : $SESS->userdata['username']),
				'comment_author_email'	=> $SESS->userdata['email'],
				'comment_author_url'	=> $SESS->userdata['url'],
				'comment_content'		=> $IN->GBL('title').' '.$IN->GBL('article_content')
			);
			
			// Check it!
			if ($this->is_spam())
			{
				// HANDLE WIKI ARTICLE SPAM
				$wiki_id = $obj->wiki_id;
				$page_id = $DB->escape_str($query->row['page_id']);
				
				// get real last revision id
				$query  = $DB->query("SELECT last_revision_id FROM exp_wiki_page WHERE wiki_id = {$wiki_id} AND page_id = {$page_id}");
				$rev_id = $query->row['last_revision_id'];

				// close revision
				$DB->query("UPDATE exp_wiki_revisions SET revision_status = 'closed' WHERE wiki_id = {$wiki_id} AND page_id = {$page_id} AND revision_id = {$rev_id}");
				
				$this->abort();
			}

		}
		
		// hook doesn't return anything
	}
	// END check_wiki_article
	


	// --------------------------------
	//	Check member registration (method called by extension)
	// --------------------------------		 
	function check_member_registration()
	{
		global $SESS, $IN, $DB;
		
		// check settings to see if comment needs to be verified
		if ($this->settings['check_member_registrations'] == 'y' AND $this->_check_user())
		{
			// Don't send these values to the service
			$ignore = array('password', 'password_confirm', 'rules', 'email' , 'url', 'username',
							'XID', 'ACT', 'RET', 'FROM', 'site_id', 'accept_terms');
			
			// Init content var
			$content = '';
			
			// Loop through posted data, add to content var
			foreach ($_POST AS $key => $val)
			{
				if (in_array($key, $ignore)) continue;
				
				$content .= $val . "\n";
			}
			
			$this->input = array(
				'user_ip'				=> $SESS->userdata['ip_address'],
				'user_agent'			=> $SESS->userdata['user_agent'],
				'comment_author'		=> $IN->GBL('username'),
				'comment_author_email'	=> $IN->GBL('email'),
				'comment_author_url'	=> $IN->GBL('url'),
				'comment_content'		=> $content
			);
			
			// Check it!
			if ($this->is_spam())
			{
				// Exit if spam
				$this->abort(TRUE);
			}
		}
		
		// hook doesn't return anything
	}
	// END check_member_registration


    // --------------------------------------------------------------------
	/**
	 * Check Solspace Freeform new entry
	 * 
	 * @access public
	 * @param  (array) 	Data passed in from extension
	 * @return (array)	Data passed back to freeform
	 */
	
	public function check_solspace_freeform_entry($data)
	{
		global $EXT, $IN, $SESS;
		
		$last_call = ( isset( $EXT->last_call ) AND is_array($EXT->last_call) ) ? $EXT->last_call : $data;
				
		// check settings to see if comment needs to be verified
		if ($this->settings['check_freeform_entries'] == 'y')
		{
			// Don't send these values to the service
			$ignore = array(
				'accept_terms',
				'author_id',
				'edit_date',
				'email' , 
				'entry_date',
				'form_name',
				'FROM', 
				'group_id',
				'name',
				'password', 
				'password_confirm', 
				'rules', 
				'site_id', 
				'url', 
				'username',
				'website',
			);
			
			// Init content var
			$content = '';
			
			// Loop through posted data, add to content var
			foreach ($data AS $key => $val)
			{
				if (in_array($key, $ignore)) continue;
				
				$content .= $val . "\n";
			}
			
			//url could come from a lot of places
			$url = isset($data['url']) ? $data['url'] : (isset($data['website']) ? $data['website'] :  $IN->GBL('url'));
			
			$this->input = array(
				'user_ip'				=> $SESS->userdata['ip_address'],
				'user_agent'			=> $SESS->userdata['user_agent'],
				'comment_author'		=> (isset($SESS->userdata['username']) ? $SESS->userdata['username'] : ''),
				'comment_author_email'	=> isset($data['email']) ? $data['email'] : '',
				'comment_author_url'	=> $url,
				'comment_content'		=> $content
			);
			
			// Check it!
			if ($this->is_spam())
			{
				// Exit if spam
				$this->abort(TRUE);
			}
		}
		
		//this needs to be returned either way
		return $last_call;
	}
	//END check_solspace_freeform_entry


    // --------------------------------------------------------------------
	/**
	 * Check Solspace User Member Register
	 * 
	 * @access public
	 * @param  (object) current instance of user
	 * @param  (array) 	array of errors already found
	 * @return (array)	Data passed back to freeform
	 */
	
	public function check_solspace_user_register($obj, $errors)
	{
		global $EXT, $IN, $SESS;
		
		$last_call = ( isset( $EXT->last_call ) AND is_array($EXT->last_call) ) ? $EXT->last_call : $errors;
		
		//if there are already errors, we dont need to bother checking
		if ( ! empty($last_call)) return $last_call;
		
		// check settings to see if comment needs to be verified
		if ($this->settings['check_ss_user_register'] == 'y')
		{
			// Don't send these values to the service
			$ignore = array(
				'password', 
				'password_confirm', 
				'rules', 
				'email' , 
				'url', 
				'username',
				'XID', 
				'ACT', 
				'RET', 
				'FROM', 
				'site_id', 
				'accept_terms',
				'captcha'
			);
			
			// Init content var
			$content = '';
			
			// Loop through posted data, add to content var
			foreach ($_POST AS $key => $val)
			{
				if (in_array($key, $ignore)) continue;
				
				$content .= $val . "\n";
			}
			
			$this->input = array(
				'user_ip'				=> $SESS->userdata['ip_address'],
				'user_agent'			=> $SESS->userdata['user_agent'],
				'comment_author'		=> $IN->GBL('username'),
				'comment_author_email'	=> $IN->GBL('email'),
				'comment_author_url'	=> $IN->GBL('url'),
				'comment_content'		=> $content
			);
			
			// Check it!
			if ($this->is_spam())
			{
				// Exit if spam
				$this->abort(TRUE);
			}
		}
		
		//this needs to be returned either way
		return $last_call;
	}
	//END check_solspace_user_entry


	// --------------------------------
	//	Check input
	// -------------------------------- 
	function is_spam()
	{
		global $SESS, $EXT, $DB, $OUT, $LANG;
		
		// init return value
		$is_spam = FALSE;
		
		// Get the Akismet class needed to perform the check
		if (!class_exists('Low_nospam'))
		{
			if (file_exists(PATH_MOD.'low_nospam/mod.low_nospam'.EXT))
			{
				require(PATH_MOD.'low_nospam/mod.low_nospam'.EXT);
			}
			else
			{
				// required low_nospam class not found: throw out error
				return $OUT->show_user_error('general', $LANG->line('low_nospam_class_not_found'));
			}
		}
			 
		// initiate NoSpam class
		$this->API = new Low_nospam();
		
		// Available but API key was not valid, throw out error
		if ($this->API->is_available && !$this->API->is_valid)
		{
			return $OUT->show_user_error('general', $LANG->line('invalid_api_key'));
		}
		
		// Not available, check settings
		if (!$this->API->is_available && $this->settings['moderate_if_unreachable'] == 'y')
		{
			$is_spam = TRUE;
		}
		
		// Available and valid, regular check
		if ($this->API->is_available && $this->API->is_valid)
		{
			$this->API->prep_data($this->input);
			$is_spam = $this->API->is_spam();
		}
		
		// return boolean
		return $is_spam;
	}
	// END is_spam


	// --------------------------------
	//	Exit!!!
	// --------------------------------
	function abort($discarded = FALSE)
	{
		global $OUT, $LANG, $EXT;
		
		// set end_script to true
		$EXT->end_script = true;
		
		$line1 = 'low_nospam_thinks_this_is_spam';
		$line2 = 'service_unreachable';
		
		if ($discarded)
		{
			$line1 .= '_discarded';
			$line2 .= '_discarded';
		}
		
		// return error message
		$feedback = ($this->API->is_available) ? $LANG->line($line1) : $LANG->line($line2);
		return $OUT->show_user_error('submission', $feedback);
		
		// hand break
		exit;
	}
	//END abort


	// --------------------------------
	//	Check current user
	// --------------------------------
	function _check_user()
	{
		global $SESS;
		
		// Don't check if we don't have to check logged-in members
		if ($this->settings['check_members'] === 'n' AND $SESS->userdata['member_id'] != 0)
		{
			$do_check = FALSE;
		}
		// Don't check if user is not in selected member groups
		elseif (is_array($this->settings['check_members']) AND !in_array($SESS->userdata['group_id'], $this->settings['check_members']))
		{
			$do_check = FALSE;
		}
		// Every other case, perform check
		else
		{
			$do_check = TRUE;
		}
		
		return $do_check;
	}
	//END _check_user


	// --------------------------------
	//	Activate Extension
	// --------------------------------
	
	function activate_extension()
	{
		global $DB;
		
		// Hooks and methods
		$hooks = array(
			'insert_comment_insert_array'	=> 'check_comment',
			'insert_trackback_insert_array'	=> 'check_trackback',
			'forum_submit_post_start'		=> 'check_forum_post',
			'gallery_insert_new_comment'	=> 'check_gallery_comment',
			'edit_wiki_article_end'			=> 'check_wiki_article',
			'member_member_register_start'	=> 'check_member_registration',
			'freeform_module_validate_end'	=> 'check_solspace_freeform_entry',
			'user_register_error_checking'	=> 'check_solspace_user_register'
		);
		
		// insert hooks and methods
		foreach ($hooks AS $hook => $method)
		{
			$DB->query(
				$DB->insert_string(
					'exp_extensions', array(
						'extension_id'	=> '',
						'class'			=> __CLASS__,
						'method'		=> $method,
						'hook'			=> $hook,
						'settings'		=> '',
						'priority'		=> 1,
						'version'		=> $this->version,
						'enabled'		=> 'y'
					)
				)
			); // end db->query
		}
	}
	// END activate_extension
	 
	 
	// --------------------------------
	//	Update Extension
	// --------------------------------	
	function update_extension($current = '')
	{
		global $DB;
		
		if ($current == '' OR $current == $this->version)
		{
			return FALSE;
		}
	    
		// Update to version 1.0.4
		// - Change check_members setting from y/n to array of member groups
		if ($current < '1.0.4')
		{
			// Get current settings
			$query = $DB->query("SELECT settings FROM exp_extensions WHERE class = '".__CLASS__."' LIMIT 1");
			$settings = unserialize($query->row['settings']);
			
			// init member groups
			$groups = array();
			
			// Default member groups if members should not be checked
			if (!isset($settings['check_members']) OR $settings['check_members'] === 'n')
			{
				$groups = $this->default_groups;
			}
			// All member groups, except superadmins, if members should be checked
			else
			{
				$query = $DB->query("SELECT group_id FROM exp_member_groups WHERE group_id != 1");
				foreach($query->result AS $row)
				{
					$groups[] = $row['group_id'];
				}
			}
			
			// update current settings
			$settings['check_members'] = $groups;
			$new_settings = $DB->escape_str(serialize($settings));
			
			// save new settings to DB
			$DB->query("UPDATE exp_extensions SET settings = '{$new_settings}' WHERE class = '".__CLASS__."'");
		}
		
		// Upate to version 1.0.5
		// - Add member registration check
		if ($current < '1.0.5')
		{
			// Get current settings
			$query = $DB->query("SELECT settings FROM exp_extensions WHERE class = '".__CLASS__."' LIMIT 1");
			$settings = unserialize($query->row['settings']);
			
			// Add new record to settings
			$settings['check_member_registrations'] = 'n';
			$new_settings = $DB->escape_str(serialize($settings));
			
			// save new settings to DB
			$DB->query("UPDATE exp_extensions SET settings = '{$new_settings}' WHERE class = '".__CLASS__."'");
			
			// Add new hook
			$DB->query(
				$DB->insert_string(
					'exp_extensions', array(
						'extension_id'	=> '',
						'class'			=> __CLASS__,
						'method'		=> 'check_member_registration',
						'hook'			=> 'member_member_register_start',
						'settings'		=> $new_settings,
						'priority'		=> 1,
						'version'		=> $this->version,
						'enabled'		=> 'y'
					)
				)
			); // end db->query
		}
	    
		//--------------------------------------------  
		//	add Solspace User and Freeform hooks
		//--------------------------------------------
		
		if ($current < '1.1.0')
		{			
			// freeform
			$DB->query(
				$DB->insert_string(
					'exp_extensions', array(
						'extension_id'	=> '',
						'class'			=> __CLASS__,
						'method'		=> 'check_solspace_freeform_entry',
						'hook'			=> 'freeform_module_validate_end',
						'settings'		=> $new_settings,
						'priority'		=> 1,
						'version'		=> $this->version,
						'enabled'		=> 'y'
					)
				)
			); // end db->query
			
			// user
			$DB->query(
				$DB->insert_string(
					'exp_extensions', array(
						'extension_id'	=> '',
						'class'			=> __CLASS__,
						'method'		=> 'check_solspace_user_register',
						'hook'			=> 'user_register_error_checking',
						'settings'		=> $new_settings,
						'priority'		=> 1,
						'version'		=> $this->version,
						'enabled'		=> 'y'
					)
				)
			); // end db->query
			
			//--------------------------------------------  
			//	default settings
			//--------------------------------------------
			
			// Get current settings
			$query = $DB->query("SELECT settings FROM exp_extensions WHERE class = '".__CLASS__."' LIMIT 1");
			
			if ($query->num_rows > 0)
			{
				$settings = unserialize($query->row['settings']);

				// Add defaults to settings
				$settings['check_freeform_entries'] = 'n';
				$settings['check_ss_user_register'] = 'n';

				$new_settings = $DB->escape_str(serialize($settings));

				// save new settings to DB
				$DB->query("UPDATE exp_extensions SET settings = '{$new_settings}' WHERE class = '".__CLASS__."'");
			}
		}
	
		// default: update version number
		$DB->query("UPDATE exp_extensions SET version = '{$this->version}' WHERE class = '".__CLASS__."'");
	}
	// END update_extension


	// --------------------------------
	//	Disable Extension
	// --------------------------------
	function disable_extension()
	{
		global $DB;
		
		$DB->query("DELETE FROM exp_extensions WHERE class = '".__CLASS__."'");
	}
	// END disable_extension
	 
}
// END CLASS
?>