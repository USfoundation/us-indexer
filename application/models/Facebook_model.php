<?php if ( !defined('BASEPATH')) exit('No direct script access allowed');

class Facebook_model extends CI_Model {
	
	var $page_access_token;
	
	function __construct() {
		parent::__construct();
		//Fetch the primary bot's access token:
		$website = $this->config->item('website');
		$this->page_access_token = $website['access_token'];
	}
	
	
	function fetch_profile($user_id){
		$ch = curl_init('https://graph.facebook.com/v2.6/'.$user_id.'?access_token='.$this->page_access_token);
		curl_setopt_array($ch, array(
				CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_RETURNTRANSFER => TRUE,
		));
		// Send the request
		return objectToArray(json_decode(curl_exec($ch)));
	}
	
	
	function fetch_settings(){
		$ch = curl_init('https://graph.facebook.com/v2.6/me/messenger_profile?fields=persistent_menu,get_started,greeting,whitelisted_domains&access_token='.$this->page_access_token);
		curl_setopt_array($ch, array(
				CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_RETURNTRANSFER => TRUE,
		));
		// Send the request
		return objectToArray(json_decode(curl_exec($ch)));
	}
	
	function set_settings(){
		
		$payload = array(
				'get_started' => array(
						'payload' => 'GET_STARTED',
				),
				'greeting' => array(
						array(
								'locale' => 'default',
								'text' => 'Hi {{user_first_name}}, I\'m your Personal Assistant Bot who would be at your service so you achieve the final goal of your Mench Bootcamp. I will:

- Assignment Updates
- Notifications & Reminders
- Answering Any Questions',
						),
				),
				'whitelisted_domains' => array(
					'http://local.mench.co',
					'https://mench.co',
				),
				'persistent_menu' => array(
					array(
						'locale' => 'default',
						'composer_input_disabled' => false,
						'call_to_actions' => array(
						    array(
						        'title' => 'My Dashboard',
						        'type' => 'nested',
						        'call_to_actions' => array(
						            array(
						                'title' => 'Leaderboard',
						                'type' => 'web_url',
						                'url' => 'https://mench.co/my/ledaerboard',
						                'webview_height_ratio' => 'tall',
						                'webview_share_button' => 'hide',
						            ),
						            array(
						                'title' => 'Assignments',
						                'type' => 'web_url',
						                'url' => 'https://mench.co/my/assignments',
						                'webview_height_ratio' => 'tall',
						                'webview_share_button' => 'hide',
						            ),
						        ),
						    ),
						    array(
						        'title' => 'Marketplace',
						        'type' => 'nested',
						        'call_to_actions' => array(
						            array(
						                'title' => 'Join a Bootcamp',
						                'type' => 'web_url',
						                'url' => 'https://mench.co/bootcamps',
						                'webview_height_ratio' => 'tall',
						            ),
						        ),
						    ),								    
						    array(
						        'title' => 'Help & Support',
						        'type' => 'postback',
						        'payload' => 'HISTORY_PAYLOAD',
						    ),
						),
					),
				),
		);
		
		//Make the call for add/update
		$ch = curl_init('https://graph.facebook.com/v2.6/me/messenger_profile?access_token='.$this->page_access_token);
		curl_setopt_array($ch, array(
				CURLOPT_CUSTOMREQUEST => 'POST',
				CURLOPT_RETURNTRANSFER => TRUE,
				CURLOPT_HTTPHEADER => array(
						'Content-Type: application/json; charset=utf-8'
				),
				CURLOPT_POSTFIELDS => json_encode($payload)
		));
		
		// Send the request
		$response = curl_exec($ch);
		
		// Check for CURL errors
		if($response === FALSE){
		    $this->Db_model->e_create(array(
		        'e_message' => 'set_settings() failed to update the settings on Facebook.',
		        'e_json' => json_encode(array(
		            'payload' => $payload,
		        )),
		        'e_type_id' => 8, //Platform Error
		    ));
		}
				
		return objectToArray(json_decode($response));
	}
	
	
	function send_message($payload){

		//Make the call for add/update
		$ch = curl_init('https://graph.facebook.com/v2.6/me/messages?access_token='.$this->page_access_token);
		curl_setopt_array($ch, array(
				CURLOPT_CUSTOMREQUEST => 'POST',
				CURLOPT_RETURNTRANSFER => TRUE,
				CURLOPT_HTTPHEADER => array(
						'Content-Type: application/json; charset=utf-8'
				),
				CURLOPT_POSTFIELDS => json_encode($payload)
		));
		
		// Send the request
		$response = curl_exec($ch);
		
		// Check for CURL errors
		if($response === FALSE){
		    $this->Db_model->e_create(array(
		        'e_message' => 'send_message() CURL Failed in sending message via Messenger.',
		        'e_json' => json_encode($payload),
		        'e_type_id' => 8, //Platform Error
		    ));
		}
		
		return objectToArray(json_decode($response));
	}
	
	
}