<?php
/*
 * CC uses UTC to schedule campaigns so need to be sure to set that explicitely
 * here.
 */
date_default_timezone_set("UTC");

//require_once 'database.php';
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


$params = parse_ini_file(__DIR__ . '/config.ini');
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

/*

Create and setup all the campaigns. It's important to note that the "signup" mailing list is the core list to which users actually indiciate
they have interest, and which we make available through some interface on our website.

The "Mailer" type lists are to be populated with users who are ready to recieve the email, each day that this script runs. So the singup mailing
list starts full or is filled by the users. The mailing list starts and empty and is filled by this script as it becomes meaningful to contact users.
*/

$all_campaigns = array();
define('STANDARD_BASE_TEMP',50);

define('BRONZE_BIRCH_THRESHOLD1',364);
define('BRONZE_BIRCH_THRESHOLD2',450);



$all_campaigns[] = new PestCampaign("Bronze Birch Notification 1", "Bronze Birch Borer Pheno Forecast", "Bronze Birch Mailer 1", BRONZE_BIRCH_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "January 1");
$all_campaigns[] = new PestCampaign("Bronze Birch Notification 2", "Bronze Birch Borer Pheno Forecast", "Bronze Birch Mailer 2", BRONZE_BIRCH_THRESHOLD2, STANDARD_BASE_TEMP,"simple", "January 1");


define('PINE_NEEDLE_THRESHOLD1',232);
define('PINE_NEEDLE_THRESHOLD2',298);
$all_campaigns[] = new PestCampaign("Pine Needle Scale Notification 1", "Pine Needle Scale Pheno Forecast", "Pine Needle Scale Mailer 1", PINE_NEEDLE_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "March 1");
$all_campaigns[] = new PestCampaign("Pine Needle Scale Notification 2", "Pine Needle Scale Pheno Forecast", "Pine Needle Scale Mailer 2", PINE_NEEDLE_THRESHOLD2, STANDARD_BASE_TEMP, "simple", "March 1");


define('EASTERN_TENT_THRESHOLD1',63);
define('EASTERN_TENT_THRESHOLD2',90);

$all_campaigns[] = new PestCampaign("Eastern Tent Caterpillar Notification 1", "Eastern Tent Caterpillar Pheno Forecast", "Eastern Tent Caterpillar Mailer 1", EASTERN_TENT_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "March 1");
$all_campaigns[] = new PestCampaign("Eastern Tent Caterpillar Notification 2", "Eastern Tent Caterpillar Pheno Forecast", "Eastern Tent Caterpillar Mailer 2", EASTERN_TENT_THRESHOLD2, STANDARD_BASE_TEMP, "simple", "March 1");


define("GYPSY_MOTH_UPPER_THRESHHOLD",104);
define("GYPSY_MOTH_LOWER_THRESHHOLD",37.4);
define("GYPSY_MOTH_THRESHOLD1",472);
define("GYPSY_MOTH_THRESHOLD2",571);

$all_campaigns[] = new PestCampaign("Gypsy Moth Notification 1", "Gypsy Moth Pheno Forecast", "Gypsy Moth Mailer 1", GYPSY_MOTH_THRESHOLD1, null, "simple", "January 1",GYPSY_MOTH_LOWER_THRESHHOLD, GYPSY_MOTH_UPPER_THRESHHOLD);
$all_campaigns[] = new PestCampaign("Gypsy Moth Notification 2", "Gypsy Moth Pheno Forecast", "Gypsy Moth Mailer 2", GYPSY_MOTH_THRESHOLD2, null, "simple", "January 1",GYPSY_MOTH_LOWER_THRESHHOLD, GYPSY_MOTH_UPPER_THRESHHOLD);

define("ALB_UPPER_THRESHOLD",86);
define("ALB_LOWER_THRESHOLD",50);
define("ALB_THRESHOLD1",579);
define("ALB_THRESHOLD2",689.75);

$all_campaigns[] = new PestCampaign("ALB Notification 1", "Asian Longhorned Beetle Pheno Forecast", "ALB Mailer 1", ALB_THRESHOLD1, null, "simple", "January 1",ALB_LOWER_THRESHOLD, ALB_UPPER_THRESHOLD);
$all_campaigns[] = new PestCampaign("ALB Notification 2", "Asian Longhorned Beetle Pheno Forecast", "ALB Mailer 2", ALB_THRESHOLD2, null, "simple", "January 1",ALB_LOWER_THRESHOLD, ALB_UPPER_THRESHOLD);



define('BAGWORM_THRESHOLD1',498);
define('BAGWORM_THRESHOLD2',600);

$all_campaigns[] = new PestCampaign("Bagworm Notification 1", "Bagworm Pheno Forecast", "Bagworm Mailer 1", BAGWORM_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "March 1");
$all_campaigns[] = new PestCampaign("Bagworm Notification 2", "Bagworm Pheno Forecast", "Bagworm Mailer 2", BAGWORM_THRESHOLD2, STANDARD_BASE_TEMP, "simple", "March 1");



define("MAGNOLIA_SCALE_THRESHOLD1",1774);
define("MAGNOLIA_SCALE_THRESHOLD2",1938);

$all_campaigns[] = new PestCampaign("Magnolia Scale Notification 1", "Magnolia Scale Pheno Forecast", "Magnolia Scale Mailer 1", MAGNOLIA_SCALE_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "January 1");
$all_campaigns[] = new PestCampaign("Magnolia Scale Notification 2", "Magnolia Scale Pheno Forecast", "Magnolia Scale Mailer 2", MAGNOLIA_SCALE_THRESHOLD2, STANDARD_BASE_TEMP, "simple", "January 1");


define('HWA_BASE_TEMP',32);
define('HWA_THRESHOLD1',16);
define('HWA_THRESHOLD2',26);
define('HWA_THRESHOLD3',1001);
$all_campaigns[] = new PestCampaign("HWA Notification 1", "Hemlock Woolly Adelgid Pheno Forecast", "HWA Mailer 1", HWA_THRESHOLD1, HWA_BASE_TEMP, "simple", "January 1");
$all_campaigns[] = new PestCampaign("HWA Notification 2", "Hemlock Woolly Adelgid Pheno Forecast", "HWA Mailer 2", HWA_THRESHOLD2, HWA_BASE_TEMP, "simple", "January 1");
$all_campaigns[] = new PestCampaign("HWA Notification 3", "Hemlock Woolly Adelgid Pheno Forecast", "HWA Mailer 3", HWA_THRESHOLD3, HWA_BASE_TEMP, "simple", "January 1");


define("WINTER_MOTH_THRESHOLD1",12);
define("WINTER_MOTH_THRESHOLD2",20);

$all_campaigns[] = new PestCampaign("Winter Moth Notification 1", "Winter Moth Pheno Forecast", "Winter Moth Mailer 1", WINTER_MOTH_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "January 1");
$all_campaigns[] = new PestCampaign("Winter Moth Notification 2", "Winter Moth Pheno Forecast", "Winter Moth Mailer 2", WINTER_MOTH_THRESHOLD2, STANDARD_BASE_TEMP, "simple", "January 1");



define("LILAC_BORER_THRESHOLD1",408);
define("LILAC_BORER_THRESHOLD2",500);

$all_campaigns[] = new PestCampaign("Lilac Borer Notification 1", "Lilac Borer (aka Ash Borer) Pheno Forecast", "Lilac Borer Mailer 1", LILAC_BORER_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "January 1");
$all_campaigns[] = new PestCampaign("Lilac Borer Notification 2", "Lilac Borer (aka Ash Borer) Pheno Forecast", "Lilac Borer Mailer 2", LILAC_BORER_THRESHOLD2, STANDARD_BASE_TEMP, "simple", "January 1");



define("EAB_THRESHOLD1",364);
define("EAB_THRESHOLD2",450);
$all_campaigns[] = new PestCampaign("EAB Notification 1", "Emerald Ash Borer Pheno Forecast", "EAB Mailer 1", EAB_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "January 1");
$all_campaigns[] = new PestCampaign("EAB Notification 2", "Emerald Ash Borer Pheno Forecast", "EAB Mailer 2", EAB_THRESHOLD2, STANDARD_BASE_TEMP, "simple", "January 1");



define("APPLE_MAGGOT_THRESHOLD1",774);
define("APPLE_MAGGOT_THRESHOLD2",900);
$all_campaigns[] = new PestCampaign("Apple Maggot Notification 1", "Apple Maggot Pheno Forecast", "Apple Maggot Mailer 1", APPLE_MAGGOT_THRESHOLD1, STANDARD_BASE_TEMP, "simple", "January 1");
$all_campaigns[] = new PestCampaign("Apple Maggot Notification 2", "Apple Maggot Pheno Forecast", "Apple Maggot Mailer 2", APPLE_MAGGOT_THRESHOLD2, STANDARD_BASE_TEMP, "simple", "January 1");



//$eab_campaign = new PestCampaign("EAB Campaign Test 2", "EAB Test SIgnup", "EAB Test Mailer List",200, 50, "double-sine", "January 1",7,32);
//$eab_campaign = new PestCampaign("EAB Campaign Test 2", "EAB Test SIgnup", "EAB Test Mailer List", 200, 50, "simple", "January 1");
//handleNotifications($cc, $cc_access_token, $access_token_v3, $eab_campaign, $debug);


$log->write(print_r($all_campaigns,true));


foreach($all_campaigns as $campaign){
	handleNotifications($cc, $cc_access_token, $access_token_v3, $campaign, $debug);
}



function handleNotifications($cc, $cc_access_token, $access_token_v3, $campaign, &$debug){
    global $blacklist;
    global $log;
    $start_size_of_list = -1;    
    $contact_list_not_yet_emailed = array();
    $contact_list_to_mail = array();
    
    $log->write("Starting the following campaign:");
    $log->write(print_r($campaign, true));
    
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

        $campaign->setMailerListV3(getCampaignListV3($campaign->getMailerListName(), $access_token_v3));
        if($campaign->getMailerListV3() == null){
                throw new Exception ("Could not find the mailer list V3");
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
        $log->write("FINISH CC ERROR");
        return;
    }

    
    /**
     * For the people who haven't been emailed yet, the script needs to know where those 
     * people are located. This will populate each contract with lat/long values
     */
    getContactLocations($contact_list_not_yet_emailed, $access_token_v3);

    foreach($contact_list_not_yet_emailed as $contact){
        $log->write("Workign with the following contact:");
        $log->write(print_r($contact,true));
        /**
         * Checks the following:
         *  a) We haven't already contacted this person (don't waste time getting threshold date)
         *  b) Email isn't in blacklist (user asked not to be contacted about this)
         *  c) Lat/long are populated (can't do anything without those values)
         */


        if(!contactMailed($contact,$campaign->getMailerList()) && !in_array(getContactEmail($contact), $blacklist) && ( $contact->lat != -1 && $contact->long != -1 ) && !isContactRemoved($contact) ){
            
            $log->write("Contact is candidate for mailing");
            $threshold_day = getThreshholdDate($contact->lat, $contact->long, $campaign);
            $log->write("Got threshold day:");
            $log->write(print_r($threshold_day,true));
       
            /**
             * In our use case, either the threshold has been hit or it hasn't. If it hasn't been
             * hit then the threshold date is null and we don't notify the user. If it's any
             * other value, then it's been hit, presumably either day of or in the next six
             * days at the time the script is run, so the time to contact the user is
             * now.
             */
            if($threshold_day != null){
                $log->write("Threshold date set, adding user to list to mail");
                /**
                 * Constant Contact API will fail if there's any data fields it
                 * doesn't recognize when you submit the request to update the contact's
                 * info. Therefore, it's appropriate to remove the lat/long values we earlier
                 * found before submitting to CC.
                 */
                if(!$debug){

                    $contact_id = getV3ContactID($contact, $access_token_v3);
                    addUserToList($campaign->getMailerListV3(),$contact_id,$access_token_v3);
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
            $log->write("Scheduling campaign!");
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
    global $log;
    $log->write("Calling get contacts");
    $log->write(print_r($the_list,true));
    
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
    
    global $log;
    $log->write("Calling getCampaignList");
    $log->write($list_name);
    
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


function getCampaignListV3($list_name, $access_token){
    
    global $log;
    $log->write("Calling getCampaignListV3 ");
    $log->write($list_name);
    
    $url = 'https://api.cc.email/v3/contact_lists?include_count=false';
	$the_list = null;

    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Cache-Control: no-cache',
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        
 

        $lists = curl_exec($ch);
        curl_close($ch);
		
        $lists = json_decode($lists);

        foreach($lists->lists as $list){
            if($list->name == $list_name){
                    $the_list = $list;
                    break;
            }
        }

 
    
    }catch(Exception $ex){
        $log->write("Failed at getting v3 list");
        $log->write(print_r($ex, true));
        $log->write("FINISH CC ERROR");
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
    global $log;
    
    $log->write("Calling getCampaign ");
    $log->write($campaign_name);
    
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
    $log->write("Calling getThresholdDate");
    $start_date = new DateTime(YEAR . "-" . $pest_model->getStartDate()->format('m') . "-" . $pest_model->getStartDate()->format('d'));
    $finish_date = (new DateTime())->add( new DateInterval('P6D'));
    $threshold_date = null;

    $url = 'https://data.usanpn.org:3006/v0/agdd/' . $pest_model->getGDDMethod() . '/pointTimeSeries?startDate=' . $start_date->format('Y-m-d') . 
            '&endDate=' . $finish_date->format('Y-m-d') .             
			'&climateProvider=NCEP' .
			'&temperatureUnit=fahrenheit' .
            '&latitude=' . trim($latitude) . 
            '&longitude=' . trim($longitude);
			
    $url .= '&agddThreshold=' . $pest_model->getThreshold();

    if($pest_model->getLowerThreshold()){
            $url .= '&lowerThreshold=' . $pest_model->getLowerThreshold();
    }else{
            $url .= '&base=' . $pest_model->getBaseTemp();
    }

    if($pest_model->getUpperThreshold()){
            $url .= '&upperThreshold=' . $pest_model->getUpperThreshold();
    }
    
    $log->write($url);
    
    try{
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        $result = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($result, true);
        $log->write($json);
        if(array_key_exists('dateAgddThresholdMet',$json) && !$json['dateAgddThresholdMet'] == null){
            $threshold_date = new DateTime($json['dateAgddThresholdMet']);
        }
    }catch(Exception $ex){
        $log->write("Could not find threshold date for:");
        $log->write($longitude . "," . $latitude);
        $log->write(print_r($ex, true));
        $log->write("FINISH ERROR");
        
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
    
    $contents = file_get_contents(__DIR__ . '/blacklist.ini');
    $list = explode("\n", $contents);
    array_shift($list);
    
    return $list;
    
}

function generateZipCodeList(){

    $new_array = array();
    try{
        $zip_file_data = array_map('str_getcsv', file(__DIR__ . '/zip-code.csv'));
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
    global $log;
    
    foreach($contact_list as $contact){
        $zip_code = getZipCodeForContact($contact, $access_token_v3);

        
        if($zip_code){            
            $coords = $zip_codes[$zip_code];
            
            $log->write("Resolved ZIP code to following coords:");
            $log->write(print_r($coords, true));
            
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
    
    $log->write("Searching for zip on following contact:");
    $log->write(print_r($contact, true));
    
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
        curl_close($ch);

        $json = json_decode($result, true);
        
        $custom_fields = $json['contacts'][0]['custom_fields'];		
        foreach($custom_fields as $cf){
            /**
             * Actually, the only way to refer to the cuomst fields is through
             * a UUID assigned to that field, an arbitrary number, which for
             * our puposes is defined earlier in the script.
             */
            $log->write("Searching custom fields for contact");
            if($cf['custom_field_id'] == ZIP_CODE_FIELD_ID){			
                $zip_code = $cf['value'];
                $log->write("Found ZIP code: " . $zip_code);
                break;
            }
        }

    }catch(Exception $ex){
        $log->write("Could not get user data.");
        $log->write(print_r($ex,true));
        $log->write("FINISH CC ERROR");
    }

    return $zip_code;
    
}

/**
 * Get refresh token saved to file.
 */
function getRefreshToken(){ 
    $params = parse_ini_file(__DIR__ . '/config.ini');
    return $params['cc_api_v3_oauth_refresh_token'];    
}


/**
 * Save (new) refresh token to disk.
 */
function saveRefreshToken($new_refresh_token){
    $config_file = file_get_contents(__DIR__ . '/config.ini');
    $matches = array();
    preg_match('/cc_api_v3_oauth_refresh_token=(.*)/', $config_file, $matches);
    $config_file = str_replace($matches[1], $new_refresh_token, $config_file);
    file_put_contents(__DIR__ . '/config.ini', $config_file);
    
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

function getV3ContactID($contact, $access_token_v3){
    $url = "https://api.cc.email/v3/contacts/contact_id_xrefs?sequence_ids=" . $contact->id;
    $contact_id = null;
    global $log;
    
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
        
 

        $contact = curl_exec($ch);
        curl_close($ch);
		
        $obj = json_decode($contact);
        $obj_arr = $obj->xrefs;
        if(count($obj_arr) > 0){
            $contact_id = $obj_arr[0]->contact_id;
        }

 
    
    }catch(Exception $ex){
        $log->write("Failed getting v3 contact");
        $log->write(print_r($ex,true));
        $log->write("FINISH CC ERROR");
    }
    
    return $contact_id;       
    
}

function addUserToList($list,$contact_id,$access_token_v3){

    $url = 'https://api.cc.email/v3/activities/add_list_memberships';
    global $log;

    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Cache-Control: no-cache',
            'Authorization: Bearer ' . $access_token_v3,
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,     '{
      "source": {
        "contact_ids": [
          "' . $contact_id . '"
        ]
      },
      "list_ids": [
        "' . $list->list_id . '"
      ]
    }'); 

    $result = curl_exec($ch);

    curl_close($ch);

 
    
    }catch(Exception $ex){
        $log->write("Error adding user to list");
        $log->write(print_r($ex,true));
        $log->write("FINISH CC ERROR");
    }
    
    return null;	
}





