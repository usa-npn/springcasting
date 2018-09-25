<?php
/*
 * CC uses UTC to schedule campaigns so need to be sure to set that explicitely
 * here.
 */
date_default_timezone_set("UTC");

require_once 'database.php';
require_once 'output_file.php';
require_once 'pest_campaign.php';
/**
 * This loads the CC API
 */
require_once 'vendor/autoload.php';

use Ctct\ConstantContact;
use Ctct\Components\EmailMarketing\Campaign;
use Ctct\Components\EmailMarketing\Schedule;
use Ctct\Components\Contacts\ContactList;
use Ctct\Components\Contacts\Contact;

/**
 * Maximum number of days in the future a springcasting event can be before we
 * will contact users.
 */
define('YEAR', (new DateTime())->format('Y'));

/**
 * There is a zip code / mailing address field in CC but we are not using that
 * because we wanted to be able to collect users' zip code by itself without
 * any further information. Therefore we created this custom field to capture
 * that data.
 */


$params = parse_ini_file('config.ini');
global $log;
$log = new OutputFile(__DIR__ . "/output.txt");

$cc = new ConstantContact($params['cc_api_key']);
$cc_access_token = $params['cc_access_token'];
define('ZIP_CODE_FIELD_ID', $params['zip_code_custom_field_id']);
define('SECRET_HASH', $params['cc_api_v3_secret_hash']);

$debug = $params['safe_mode'];

global $blacklist;
global $zip_codes;
$blacklist = generateBlacklist();
$zip_codes = generateZipCodeList();

$access_token_v3 = fetchAccessToken();


$eab_campaign = new PestCampaign("EAB Campaign Test", "EAB Test SIgnup", "EAB Test Mailer List", 32, 1000);


handleNotifications($cc, $cc_access_token, $access_token_v3, $eab_campaign, $debug);


function handleNotifications($cc, $cc_access_token, $access_token_v3, $campaign, &$debug){
    global $blacklist;
    global $log;
    $start_size_of_list = -1;    
    $contact_list_not_yet_emailed = array();
    $contact_list_to_mail = array();
    
    /**
     * If either the lists of the campaign come back as null, or otherwise can't
     * be found on the CC server, then the process is terminated as not much
     * else can be done without those details.
     * 
     */
    try{
        $campaign->setList(getCampaignList($cc, $cc_access_token, $campaign->getListName()));
        if($campaign->getList() == null){
            throw new Exception ("Could not find the list");
        }
        
        $campaign->setMailerList(getCampaignList($cc, $cc_access_token, $campaign->getMailerListName()));
        if($campaign->getMailerList() == null){
            throw new Exception ("Could not find the mailer list");
        }        
        
        
        $campaign->setCampaign(getCampaign($cc, $cc_access_token, $campaign->getCampaignName()));        
        if($campaign->getCampaign() == null){
            throw new Exception("Could not find the campaign");
        }
        
        
        /**
         * Collect some info on the number of people in each list, to be used 
         * later.
         */
        $contact_list_not_yet_emailed = getContacts($cc, $cc_access_token, $campaign->getList(), $contact_list_not_yet_emailed, $next=null);
        $contact_list_to_mail = getContacts($cc, $cc_access_token, $campaign->getMailerList(), $contact_list_to_mail, $next=null);
        $start_size_of_list_to_mail = count($contact_list_to_mail);
        
        $log->write("Found a start size in the list of: " + $start_size_of_list);
        
        
    } catch (Exception $ex) {
        $log->write("There was an issue estbalishing list or campaign: " . $campaign->getListName() . " " . $campaign->getCampaignName());
        $log->write(print_r($ex, true));
        return;
    }

    
    /**
     * For the people who haven't been emailed yet, the script needs to know where those 
     * people are located. This will populate each contract with lat/long values
     */
    getContactLocations($contact_list_not_yet_emailed, $access_token_v3);
    
    foreach($contact_list_not_yet_emailed as $contact){
        /**
         * Checks the following:
         *  a) We haven't already contacted this person (don't waste time getting threshold date)
         *  b) Email isn't in blacklist (user asked not to be contacted about this)
         *  c) Lat/long are populated (can't do anything without those values)
         */
        if(!contactMailed($contact,$campaign->getMailerList()) && !in_array(getContactEmail($contact), $blacklist) && ( $contact->lat != -1 && $contact->long != -1 ) && !isContactRemoved($contact) ){
                    
            $threshold_day = getThreshholdDate($contact->lat, $contact->long, $campaign);
            
            /**
             * In our use case, either the threshold has been hit or it hasn't. If it hasn't been
             * hit then the threshold date is null and we don't notify the user. If it's any
             * other value, then it's been hit, presumably either day of or in the next six
             * days at the time the script is run, so the time to contact the user is
             * now.
             */
            if($threshold_day != null){
                $contact->lists[] = $campaign->getMailerList();
                
                /**
                 * Constant Contact API will fail if there's any data fields it
                 * doesn't recognize when you submit the request to update the contact's
                 * info. Therefore, it's appropriate to remove the lat/long values we earlier
                 * found before submitting to CC.
                 */
                if(!$debug){
                    unset($contact->lat);
                    unset($contact->long);
                    $cc->contactService->updateContact($cc_access_token, $contact, array('action_by' => 'ACTION_BY_OWNER'));
                }
            }
        }
        
    }
    

    try{
        /**
         * Find the size of the contact list now that the script has popualted
         * it with any new people to email. If the size is bigger than before
         * then schedule the campaign to be sent.
         */
        $contact_list = array();
        $contact_list = getContacts($cc, $cc_access_token, $campaign->getMailerList(), $contact_list, $next=null);
        
        $finish_size_of_list = count($contact_list);
        $log->write("Finish size of list: " . $finish_size_of_list);
        
        if($start_size_of_list_to_mail < 0 || ($finish_size_of_list - $start_size_of_list_to_mail) == 0){
            throw new Exception("No one on the list to contact, not scheduling the campaign.");
        }        
        
        
        if(!$debug){

            $date = new DateTime();
            $date->add(new DateInterval('PT25M'));
            $schedule = new Schedule();
            $schedule->scheduled_date = $date->format('Y-m-d\TH:i:s');
                
            $cc->campaignScheduleService->addSchedule($cc_access_token, $campaign->getCampaign()->id, $schedule); 
        }

    }catch(Exception $ex){
        $log->write(print_r($ex, true));
    }    
}



/**
 * There's several places where the contact's email needs to be used directly.
 * The email address is buried in an array in the response from CC so this function
 * can dig the first one associated with the contact out of that data structure.
 */
function getContactEmail($contact){
    $email = null;
    foreach($contact->email_addresses as $address){
        if(property_exists($address, 'email_address') && $address->email_address != null){
            $email = $address->email_address;
            break;
        }
        
    }
    
    return $email;
    
}


/**
* Will get a complete enumeration of users that are members of a particular contact list.
**/
function getContacts($cc, $cc_access_token, $the_list, &$arr, $next=null){
    print "Calling getContacts with: " . $next;
    print "Count arr:" . count($arr);

    $params = array();
    if($next && !empty($next)){
            $params['next'] = $next;
    }
	
    $results = $cc->contactService->getContactsFromList($cc_access_token, $the_list->id, $params);
	
    $some_contacts = array_merge($arr, $results->results);

    $next = $results->next;
    if($next && !empty($next)){
            $some_contacts = getContacts($cc, $cc_access_token, $the_list, $some_contacts, $next);
    }

    return $some_contacts;
}



/**
 * Will find the ContactList from the CC account based on that list's name.
 * @param ContactContact $cc
 * @param string $cc_access_token
 * @param string $list_name
 * @return ContactList
 */
function getCampaignList($cc, $cc_access_token, $list_name, $next=null){
    
    $params = array();
    
    if($next){
        $params['next'] = $next;
    }    
    
    $the_list = null;
    $lists = $cc->listService->getLists($cc_access_token, $params);
    /*
     * The CC API doesn't allow you to filter lists by name, so have to iterate
     * through each one to find it.
     */
    foreach($lists as $list){
        if($list->name == $list_name){
            $the_list = $cc->listService->getList($cc_access_token, $list->id);
            break;
        }
    }
    
    if($the_list == null && $lists->next != null){
        $the_list = getCampaignList($cc, $cc_access_token, $list_name, $lists->next);
    }    
    
    return $the_list;
}


/**
 * Will find the Campaign from the CC account based on that campaign's name.
 * @param ConstantContact $cc
 * @param string $cc_access_token
 * @param string $campaign_name
 * @return Campaign
 */
function getCampaign($cc, $cc_access_token, $campaign_name, $next=null){
    $params = array();
    
    if($next){
        $params['next'] = $next;
    }
    $campaigns = $cc->emailMarketingService->getCampaigns($cc_access_token, $params);
    $the_campaign = null;
    /*
     * The CC API doesn't allow you to filter campaigns by name, so have to iterate
     * through each one to find it.
     */
    foreach($campaigns->results as $campaign){

        if($campaign->name == $campaign_name){
            $the_campaign = $cc->emailMarketingService->getCampaign($cc_access_token, $campaign->id);
            break;
        }
    }
    
    if($the_campaign == null && $campaigns->next != null){
        $the_campaign = getCampaign($cc, $cc_access_token, $campaign_name, $campaigns->next);
    }
    
    return $the_campaign;    
}





/**
 * Queries the NPN data services to find out the date a particular threshold temp
 * is hit. Returns null if that temp hasn't yet been hit. Uses a start date 
 * appripriate for the current year, and check for values through 6 days after
 * current date.
 */
function getThreshholdDate($latitude, $longitude, $pest_model){
    global $log;
    $start_date = new DateTime(YEAR . "-" . $pest_model->getStartDate()->format('m') . "-" . $pest_model->getStartDate()->format('d'));
    $finish_date = (new DateTime())->add( new DateInterval('P6D'));
//    $start_date = new DateTime("2017-01-01");
//    $finish_date = new DateTime("2017-09-05");
    $threshold_date = null;
    
    $url = 'https://data-dev.usanpn.org:3006/v0/agdd/agddPointTimeSeries?startDate=' . $start_date->format('Y-m-d') . 
            '&endDate=' . $finish_date->format('Y-m-d') . 
            '&base=' . $pest_model->getBaseTemp() . 
            '&latitude=' . $latitude . 
            '&longitude=' . $longitude . 
            '&agddThreshold=' . $pest_model->getThreshold();
    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        $result = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($result, true);

        if($json['dateAgddThresholdMet']){
            $threshold_date = new DateTime($json['dateAgddThresholdMet']);
        }
    }catch(Exception $ex){
        $log->write("Could not find threshold date for:");
        $log->write($longitude . "," . $latitude);
        $log->write(print_r($pest_model, true));
        $log->write(print_r($ex, true));
        
    }

    return $threshold_date;

}



/**
 * There's a few cases where we don't want to operate a user if they've been
 * 'removed' from the CC mailing list. This function checks the status value
 * and returns the appropriate value.
 * 
 * @param Contact $contact
 * @return boolean
 */
function isContactRemoved(&$contact){
    $return = true;
    if($contact != null && $contact->status != null 
            && $contact->status != "REMOVED"
            && $contact->status != "OPTOUT" ){
        $return = false;
    }
    return $return;
}







function generateBlacklist(){
    
    $contents = file_get_contents('blacklist.ini');
    $list = explode("\n", $contents);
    array_shift($list);
    
    return $list;
    
}

function generateZipCodeList(){

    $new_array = array();
    try{
        $zip_file_data = array_map('str_getcsv', file('zip-code.csv'));
        foreach($zip_file_data as $zip){
            $new_array[$zip[0]] = array($zip[1],$zip[2]);
        }

    }catch(Exception $ex){
        $this->log("Could not generate list of ZIPs");
        $this->log("Terminiating program");
        die();
    }
    
    return $new_array;
}

/**
 * Will iterate through the whole list of contacts,
 * find the zip code associated with each contact
 * and resolve that to a lat/long which is then
 * stored with the contact object from CC.
 */
function getContactLocations(&$contact_list, $access_token_v3){
    global $zip_codes;
    
    foreach($contact_list as $contact){
        $zip_code = getZipCodeForContact($contact, $access_token_v3);
        
        if($zip_code){            
            $coords = $zip_codes[$zip_code];
            $contact->lat = $coords[0];
            $contact->long = $coords[1];
        }else{
            $contact->lat = -1;
            $contact->long = -1;
        }
    }
}

/**
 * Checks to see if a contact is already in a particular
 * mailing list. This is used to prevent the script from
 * trying to add a user to a list more than once.
 */
function contactMailed($contact, $the_list){
    $is_mailed = false;
    foreach($contact->lists as $list){
        if($list->id == $the_list->id){
            $is_mailed = true;
            break;
        }
    }

    return $is_mailed;
}


/**
 * Currently the only V3 function in CC API used in this script. Tries to find
 * zip code info for the user as stored in the "Zip Code" custom field.
 */
function getZipCodeForContact(&$contact, $access_token_v3){
    
    global $log;
    $zip_code = null;
    $email = urlencode(getContactEmail($contact));
    $url = 'https://api.cc.email/v3/contacts?status=all&email=' . $email . '&include=custom_fields&include_count=false';
    
    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Cache-Control: no-cache',
            'Authorization: Bearer ' . $access_token_v3,
            'Content-Type: application/json',
            'Accept: application/json'
        ));   
        
        $result = curl_exec($ch);
        print_r($result);
        curl_close($ch);

        $json = json_decode($result, true);
        
        $custom_fields = $json['contacts'][0]['custom_fields'];
        foreach($custom_fields as $cf){
            /**
             * Actually, the only way to refer to the cuomst fields is through
             * a UUID assigned to that field, an arbitrary number, which for
             * our puposes is defined earlier in the script.
             */
            if($cf['custom_field_id'] == ZIP_CODE_FIELD_ID){
                $zip_code = $cf['value'];
                break;
            }
        }

    }catch(Exception $ex){
        $log->write("Could not get user data.");        
    }

    return $zip_code;
    
}

/**
 * Get refresh token saved to file.
 */
function getRefreshToken(){
    $params = parse_ini_file('config.ini');
    return $params['cc_api_v3_oauth_refresh_token'];    
}


/**
 * Save (new) refresh token to disk.
 */
function saveRefreshToken($new_refresh_token){
    $config_file = file_get_contents('config.ini');
    $matches = array();
    preg_match('/cc_api_v3_oauth_refresh_token=(.*)/', $config_file, $matches);
    $config_file = str_replace($matches[1], $new_refresh_token, $config_file);
    file_put_contents('config.ini', $config_file);
    
}

/**
 * V3 of CC API uses OAuth which means that access tokens expire after
 * one day and have to be refreshed with a refresh token (which expires after
 * it is used). Workflow is to get existing refresh token; execute new access
 * token request; Save new refresh token to disk; Return retrieved access token.
 */
function fetchAccessToken(){
    global $log;
    $refresh_token = getRefreshToken();
    $new_refresh_token = null;
    $access_token = null;
    $url = 'https://idfed.constantcontact.com/as/token.oauth2?refresh_token=' . $refresh_token . '&grant_type=refresh_token';
    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'application/x-www-form-urlencoded',
            'authorization: Basic ' . SECRET_HASH
        ));   
                
        $result = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($result, true);
        if($json['access_token']){
            $access_token = $json['access_token'];            
        }else{
            throw new Exception("Could not parse access token");
        }
        
        if($json['refresh_token']){
            $new_refresh_token = $json['refresh_token'];
        }else{
            throw new Exception("Could not parse refresh token");
        }
        
    }catch(Exception $ex){
        $log->write("Failed fetching access token");
        $log->write("Refresh token is: " . $refresh_token);
        $log->write(print_r($ex, true));
        $log->write("Terminating script.");        
        die();
    }
    
    saveRefreshToken($new_refresh_token);
    
    return $access_token;
}





