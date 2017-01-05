<?php


/*
 * CC uses UTC to schedule campaigns so need to be sure to set that explicitely
 * here.
 */
date_default_timezone_set("UTC");

require_once 'database.php';
require_once 'output_file.php';
/**
 * This loads the CC API
 */
require_once 'vendor/autoload.php';

/**
 * This sets up some of the entity names as they exist in CC
 */
define('LEAF_LIST_NAME', 'lee-test-leaf-list');
define('LEAF_CAMPAIGN_NAME', 'Lee Test Leaf');

define('BLOOM_LIST_NAME', 'lee-test-bloom-list');
define('BLOOM_CAMPAIGN_NAME', 'Lee Test Bloom');

/**
 * Maximum number of days in the future a springcasting event can be before we
 * will contact users.
 */
define('THRESHOLD', 3);
define('YEAR', (new DateTime())->format('Y'));


/**
 * These are references to field names in the usanpn2.Springcasting table,
 * defined here so we can reference those fields dynamically in our queries.
 */
define('LEAF_EVENT', 'Leaf_Date');
define('BLOOMD_EVENT', 'Bloom_Date');

define('LEAF_PHENOPHASE', 'leaf');
define('BLOOM_PHENOPHASE', 'bloom');

/**
 * All custom fields in CC that are accessible via the API have to be named as
 * CustomFieldX
 */
define('CC_STATIONS_NAME_FIELD_NAME', 'CustomField3');

use Ctct\ConstantContact;
use Ctct\Components\EmailMarketing\Campaign;
use Ctct\Exceptions\CtctException;
use Ctct\Components\EmailMarketing\MessageFooter;
use Ctct\Components\EmailMarketing\Schedule;
use Ctct\Components\Contacts\ContactList;
use Ctct\Components\Contacts\Contact;
use Ctct\Components\Contacts\EmailAddress;
use Ctct\Components\Contacts\CustomField;

$params = parse_ini_file('config.ini');
$log = new OutputFile("output.txt");

$cc = new ConstantContact($params['cc_api_key']);
$cc_access_token = $params['cc_access_token'];


$mysql = null;
$pgsql = null;

try{
    $mysql = new Database("mysql",
            $params['mysql_host'], 
            $params['mysql_user'],
            $params['mysql_pw'],
            $params['mysql_db'],
            $params['mysql_port'], 
            $log
            );

    $pgsql = new Database("pgsql",
            $params['pgsql_host'], 
            $params['pgsql_user'],
            $params['pgsql_pw'],
            $params['pgsql_db'],
            $params['pgsql_port'], 
            $log        
            );
}catch(Exception $ex){
    $log->write("There was an error establishing database connections. Terminating job.");
    $log->write(print_r($ex, true));
    die();
}


handleNotifications($cc, $cc_access_token, LEAF_EVENT, LEAF_PHENOPHASE, LEAF_LIST_NAME, LEAF_CAMPAIGN_NAME, $mysql, $pgsql, $log);

handleNotifications($cc, $cc_access_token, BLOOMD_EVENT, BLOOM_PHENOPHASE, BLOOM_LIST_NAME, BLOOM_CAMPAIGN_NAME, $mysql, $pgsql, $log);


function handleNotifications($cc, $cc_access_token, $event, $phenphase, $list_name, $campaign_name, &$mysql, &$pgsql, &$log){
    
    $the_list = null;
    $the_campaign = null;
    $email_list = array();

    $current_date = new DateTime();    
//$current_date = new DateTime('2016-04-15');
    $three_day = $current_date->add(new DateInterval('P3D'));
    
    /**
     * If either the list of the campaign come back as null, or otherwise can't
     * be found on the CC server, then the process is terminated as not much
     * else can be done without those details.
     * 
     * After this, all contacts are removed from the list, as we don't want to
     * email the people that received the email on a previous day. This must
     * be done at the beginnning of the script, because the email scheduling
     * needs at least 25 minutes lead time, so it can't be done synchronously
     * at the end of the script.
     */
    try{
        $the_list = getCampaignList($cc, $cc_access_token, $list_name);
        if($the_list == null){
            throw new Exception ("Could not find the list");
        }
        
        
        $the_campaign = getCampaign($cc, $cc_access_token, $campaign_name);
        if($the_campaign == null){
            throw new Exception("Could not find the campaign");
        }
        
        
        removeAllContactsFromList($cc_access_token, $cc, $the_list, $log);


    } catch (Exception $ex) {
        $log->write("There was an issue estbalishing list or campaign: " . $list_name . " " . $campaign_name);
        $log->write(print_r($ex, true));
        die();
    }

    
    /**
     * Now to get all stations which contains lilacs and havne't already undergone
     * the springcasting event and iterate over them. For each such station,
     * check the gis database to see if the station will pop in the next three
     * days.
     */
    $query = getStationQuery($event);
    $station_data = $mysql->getResults($query);    
    while($row = $station_data->fetch()){
        
        $six_day = null;
        
        try{
            $six_data = $pgsql->getResults(getSIXQuery($phenphase, $three_day->format('Y-m-d'), $row['Longitude'], $row['Latitude']));    
            $six_day = $six_data->fetch();
            $six_day = $six_day['st_value'];
        }catch(Exception $ex){
            $log->write("Couldn't find six data for station: " . $row['Station_ID'] . " - " . $row['Station_Name']);
            $log->write(print_r($ex, true));
            continue;
        }


        $station = new Station();
        $station->id = $row['Station_ID'];
        $station->name = $row['Station_Name'];
        $num_days = evaluteSIX($six_day); 
        
        
        /**
         * Check and see that an six value is availabgle and if so, if it is within
         * the threshold period to nofiy users.
         */
        if($six_day != null && !empty($six_day) && $num_days >= 0 && $num_days <= THRESHOLD){
            
            /**
             * With a station that has popped, the first thing to do is change
             * it's status as such in the database. If an error happens while
             * trying to achieve that, then nothing should be done with it, just
             * keep processing on the next station.
             */
            try{
                updateStationSpringCast($station->id, $event, $mysql, getDateFromDay($six_day, YEAR));
            }catch(Exception $ex){
                $log->write("Had a problem updating station's spring cast status: " . $station->id);
                $log->write(print_r($ex, true));
                continue;
            }
            
            /**
             * In our list of people to email, add the person who's created the
             * site.
             */
            addPersonToContactList($row['Person_ID'], $row['email'], $email_list, $station);
            
            /**
             * There's additionally processing that needs to happen if the site
             * is a group site (that is also not of a group like Signs 
             * of the Season)
             */
            if($row['Network_ID'] != null && !empty($row['Network_ID']) && $row['no_group_site'] == 0){
                
                /**
                 * From the query we should have info about any admins that
                 * control the group, so schedule each of those people to be
                 * emailed.
                 */
                $admin_ids = explode(",", $row['admin_ids']);
                $admin_emails = explode(",", $row['admin_emails']);
                $c = count($admin_emails);
                for($i=0;$i<$c;$i++){
                    addPersonToContactList($admin_ids[$i], $admin_emails[$i], $email_list, $station);
                }
                
                /**
                 * Get a list of anyone that has ever observed at the site in
                 * question and schedule them for notification. 
                 * This will presumably capture any group members that
                 * operate at the site. We have to make a seperate query because
                 * it was not possible to write a SQL statement  that would run
                 * quickly enough to get this information from the initial SELECT
                 * against the Station table.
                 */
                $query = getStationObservers($station->id);                
                $station_observers = $mysql->getResults($query);
                
                while($observer_row = $station_observers->fetch()){
                    addPersonToContactList($observer_row['Person_ID'], $observer_row['email'], $email_list, $station);
                }
                
                
            }                       
        }

    }
    
/*
foreach($email_list as $person){
    $str = "";
    foreach($person->stations as $station){
        $str .= ($station->six_day . ",");
    }
    $log->write($person->id . "," . $person->email . "," . count($person->stations) . "," . $str);
}
die();
 * 
 */
 
    

    
    /**
     * Now  the list of people to contact has been built, as well as each of the
     * stations to which those people are observing. For each observer, we want
     * to give some specific messaging about which stations to observe at. Collect
     * that information and send it to CC.
     */
    foreach($email_list as $entity){
        
        try{
            /**
             * If we get no results for the email from CC, then we need to first
             * add that user to the mailing list.
             * 
             */
            $results = $cc->contactService->getContacts($cc_access_token, array('email' => $entity->email));            
            if(count($results) == 0){                
                createNewContact($entity, $cc, $cc_access_token, $the_list);
            }else{

                $contact = $results->results[0];
                
                /**
                 * There's a possibility that the person has opted out from CC
                 * so in that case, we can ignore updating that person's details.
                 */
                if(isContactRemoved($contact)){
                    continue;
                }

                /**
                 * Make sure the user is both subscribed to the list and add the
                 * list of sites variable to their account.
                 */

                $contact->lists[] = $the_list;
                setContactSitesValue($contact, $entity);
                $cc->contactService->updateContact($cc_access_token, $contact);
            }
        }catch(Exception $ex){
            $log->write("There was a problem adding a person to the day's listing: " . $entity->email . " - " . $the_list->name);
            $log->write(print_r($ex, true));
            continue;
        }

    }

    try{     
        $date = new DateTime();
        $date->add(new DateInterval('PT25M'));
        $schedule = new Schedule();
        $schedule->scheduled_date = $date->format('Y-m-d\TH:i:s\.000\Z');
                
        $cc->campaignScheduleService->addSchedule($cc_access_token, $the_campaign->id, $schedule); 

    }catch(Exception $ex){
        $log->write(print_r($ex, true));
    }    
}


/**
 * 
 * This function is used to add a person to the list of people to contact on the
 * given day the script is run. It is based on the perosn's details, and the
 * station they are related to, or may be observing lilacs at.
 * 
 * This function has checks to prevent the same person being added to the list
 * twice or listing the person's stations more than once (as there are different
 * reasons why a perosn may need to be contacted about a lilac at a site).
 * 
 * @param int $person_id
 * @param string $email
 * @param LilacPerson $email_list
 * @param Station $station
 */
function addPersonToContactList($person_id, $email, &$email_list, &$station){
    
    if(!array_key_exists($person_id, $email_list)){
        $person = new LilacPerson();
        $person->id = $person_id;
        $person->email = $email;

        $person->stations[$station->id] = $station;
        $email_list[$person->id] = $person;
    }else{
        if(!array_key_exists($station->id, $email_list[$person_id]->stations)){
            $email_list[$person_id]->stations[$station->id] = $station;
        }
    }
}

/**
 * 
 * Sometimes a user is in our system / database, but isn't on our CC mailing
 * list. This function does the requisite work to add someone to CC, as well as
 * add them to the relevant list, before trying to email them.
 * 
 * @param LilacPerson $person
 * @param ConstantContact $cc
 * @param string $cc_access_token
 * @param ContactList $the_list
 */
function createNewContact($person, $cc, $cc_access_token, $the_list){
    $new_contact = new Contact();
    
    $email_entity = new EmailAddress();
    $email_entity->email_address = $person->email;
    $email_entity->opt_in_source = "ACTION_BY_OWNER";
    
    $new_contact->email_addresses = array($email_entity);
    $new_contact->lists = array($the_list);
    $new_contact->status = "ACTIVE";
    
    setContactSitesValue($new_contact, $person);
    
    $cc->contactService->addContact($cc_access_token, $new_contact, array('action_by' => 'ACTION_BY_OWNER'));
}

/**
 * Because we have a new list of observers to contact each day, but we want to
 * keep/maintain the same contact list in our CC account, this will depopulate
 * a contactlist.
 * 
 * If a list can't be properly depopulated for any reason, then we terminate this
 * entire process from the function.
 * 
 * @param string $cc_access_token
 * @param ConstantContact $cc
 * @param ContactList $the_list
 * @param Log $log
 * @throws Exception
 */
function removeAllContactsFromList($cc_access_token, $cc, $the_list, &$log){
    $contacts = array();
    
    /*
     * Find all contacts
     */
    try{
        $contacts = $cc->contactService->getContactsFromList($cc_access_token, $the_list->id);
    }catch(Exception $ex){
        $log->write("Couldn't find contacts for list: " . $the_list->name);
        $log->write(print_r($ex, true));
        die();
    }
    
    /**
     * Iterate through each contact ...
     */
    foreach($contacts->results as $res){
        $contact = null;
        
        try{
            $contact = $cc->contactService->getContact($cc_access_token, $res->id);
            if($contact == null){
                throw new Exception("Could not find contact.");
            }
            
        }catch(Exception $ex){
            $log->write("Couldn't retreive contact information for: " . $res->id);
            $log->write(print_r($ex, true));
            die();
        }
        
        if(isContactRemoved($contact)){
            continue;
        }
        
        /**
         * Find that contact's applicable list
         */
        $i = 0;
        $indx = -1;
        foreach($contact->lists as $list){
            if($list->id == $the_list->id){
                $indx = $i;
                break;
            }
            $i++;
            
        }
        /**
         * Remove that contact from the list and then save the contact
         */
        try{
            unset($contact->lists[$indx]);
            $contact->lists = array_values($contact->lists);
            $cc->contactService->updateContact($cc_access_token, $contact);
        }catch(Exception $ex){
            $log->write("Unable to remove contact from list. Have to terminate.");
            $log->write(print_r($ex, true));
            die();
        }
        
    }
}


/**
 * Will find the  ContactList from the CC account based on that list's name.
 * @param ContactContact $cc
 * @param string $cc_access_token
 * @param string $list_name
 * @return ContactList
 */
function getCampaignList($cc, $cc_access_token, $list_name){
    
    $the_list = null;
    $lists = $cc->listService->getLists($cc_access_token);
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
    
    return $the_list;
}


/**
 * Will find the Campaign from the CC account based on that campaign's name.
 * @param ConstantContact $cc
 * @param string $cc_access_token
 * @param string $campaign_name
 * @return Campaign
 */
function getCampaign($cc, $cc_access_token, $campaign_name){
    $campaigns = $cc->emailMarketingService->getCampaigns($cc_access_token);
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
    
    return $the_campaign;    
}


/**
 * Takes the name of a springcast event (leaf or bloom), and generates a query
 * to get all stations that have lilacs and also haven't undergone the applicable
 * springcasting event this year.
 * 
 * Returns the query.
 * 
 * @param string $event_name
 * @return string
 */
function getStationQuery($event_name){
    
    return "SELECT Station.Station_ID, Station.Station_Name, Station.Latitude, Station.Longitude,
    Person.email, Person.Person_ID,
    Network_Station.Network_ID, Network.no_group_site,
    springcast.Leaf_Date, springcast.Bloom_Date,

    GROUP_CONCAT(DISTINCT admins.Person_ID) `admin_ids`, 
    GROUP_CONCAT(DISTINCT admins.email) `admin_emails`
    
    FROM usanpn2.Station
    LEFT JOIN usanpn2.Person
    ON Person.Person_ID = Station.Observer_ID
    LEFT JOIN usanpn2.Network_Station
    ON Network_Station.Station_ID = Station.Station_ID    
    LEFT JOIN usanpn2.Station_Species_Individual
    ON Station_Species_Individual.Individual_ID = Station.Station_ID

    LEFT JOIN usanpn2.springcast
    ON springcast.Station_ID = Station.Station_ID AND springcast.Year = " . YEAR . "    
    
    
    LEFT JOIN usanpn2.Network_Person
    ON Network_Person.Network_ID = Network_Station.Network_ID
    LEFT JOIN usanpn2.App_Role_Network_Person
    ON app_role_network_person.Network_Person_ID = network_person.Network_Person_ID 
    
    LEFT JOIN usanpn2.Network
    ON Network.Network_ID = Network_Station.Network_ID
    
    LEFT JOIN usanpn2.Person `admins`
    ON admins.Person_ID = Network_Person.Person_ID AND app_role_network_person.Role_ID = 1
    

    WHERE Station_Species_Individual.Species_ID IN (35, 36)
    and (springcast.Springcast_ID IS NULL OR springcast." . $event_name . " IS NULL)
    GROUP BY Station.Station_ID";     
}

/**
 * Generateas a query to get observer details for anyone that has ever observed
 * at site id passed in.
 * 
 * Returns the generated query. 
 * 
 * @param int $station_id
 * @return string
 */
function getStationObservers($station_id){
    return "SELECT Person.Person_ID, Person.email
    FROM usanpn2.Network_Station
    LEFT JOIN usanpn2.station_species_individual
    ON station_species_individual.Station_ID = Network_Station.Station_ID
    LEFT JOIN usanpn2.Observation
    ON observation.Individual_ID = station_species_individual.Individual_ID
    LEFT JOIN usanpn2.Person
    ON Person.Person_ID = Observation.Observer_ID
    WHERE Network_Station.Station_ID = " . $station_id . "
    AND Observation.Observation_ID IS NOT NULL
    AND Person.Person_ID > 0
    GROUP BY Observation.Observer_ID";

}

/**
 * Generates the query needed to find a particualr SI-X value at a particular 
 * location. 
 * 
 * @param string $phenophase
 * @param string $date
 * @param long $longitude
 * @param long $latitude
 * @return string
 */
function getSIXQuery($phenophase, $date, $longitude, $latitude){
    
    $lat_long_str = $longitude . "," . $latitude;
    
    return "SELECT 
                st_value(rast,ST_SetSRID(ST_Point(" . $lat_long_str . "),4269))
                       FROM ncep_spring_index
                       WHERE rast_date = '" . $date . "'
                       AND phenophase = '" . $phenophase . "'
                       AND plant = 'average'
                       AND ST_Intersects(rast, ST_SetSRID(ST_MakePoint(" . $lat_long_str . "),4269))";
    
    
}




/**
 * Simple utility function to compare the current day of year to a si-x value.
 * 
 * @param int $value
 * @return int
 */
function evaluteSIX($value){
    $date = new DateTime();
//$date = new DateTime('2016-04-15');
    $current_doy = $date->format('z') + 1;
    
    return ( $value - $current_doy );
    
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


/**
 * Every time we email a user, we need to update their account details, so that
 * they relevant site names where they will find lilacs about to leaf/bloom are
 * listed, before we email them. This function sets that detail.
 * 
 * @param Contact $contact
 * @param Array $contact_data
 * @return void
 */
function setContactSitesValue(&$contact, $contact_data){
    
    $indx = findContactCustomFieldIndex($contact, CC_STATIONS_NAME_FIELD_NAME);    
    $value = "";
    $c = count($contact_data->stations);
    
    for($i=0; $i<$c; $i++){
        $divider = ", ";
        if($i==0){
            $divider = "";            
        }
        if($c > 1 && $i == ($c - 1)){
            $divider = " and ";
        }
        $station = array_pop($contact_data->stations);
        $value .= ($divider . $station->name);
    }
    
    if($indx == -1){
        $cf = CustomField::create(array('name'=> CC_STATIONS_NAME_FIELD_NAME, 'value' => $value));
        $contact->custom_fields[] = $cf;        
    }else{
        $contact->custom_fields[$indx]->value = $value;    
    }
    

    
}


/**
 * The structure of the Contact object lists all the custom fields in an
 * arbitrary order. This utlity function will find the index of a given custom
 * field in the array of custom fields for easy reference / update. 
 * 
 * Returned value is in the index of the field in the custom fields array. 
 * Returns -1 if no such field exists.
 * 
 * @param Contact $contact
 * @param String $field_name
 * @return int 
 */
function findContactCustomFieldIndex($contact, $field_name){
    $indx=-1;
    $num_custom_fields = count($contact->custom_fields);
    for($i=0; $i < $num_custom_fields; $i++){
        if($contact->custom_fields[$i]->name == $field_name){
            $indx = $i;
            break;
        }
    }
    
    return $indx;
}


/**
 * Utility function to convert a day of year value to an actual date.
 * 
 * @param int $dayOfYear
 * @param int $year
 * @return DateTime
 */
function getDateFromDay($dayOfYear, $year) {
  $date = DateTime::createFromFormat('z Y', strval($dayOfYear) . ' ' . strval($year));
  return $date;
}

/**
 * When a springcasting event is triggered for a station, we need to update that
 * status in the database so that we don't email people twice about the same
 * station. This function will handle that logic. Future queries to the database
 * to retreive applicable lilac stations will reference the springcasting table
 * accordingly.
 * 
 * @param int $station_id
 * @param string $event
 * @param PDO $mysql
 * @param DateTime $date
 */
function updateStationSpringCast($station_id, $event, &$mysql, $date){
    
    /*
     * This will find the appropriate record with which to work/update
     */
    $springcast_id = checkOrCreateSpringCastExists($station_id, $mysql);
    
    $query = "UPDATE usanpn2.Springcast SET " . $event . "= '" . $date->format('Y-m-d') . "' WHERE Springcast_ID=" . $springcast_id;
    
    $mysql->runQuery($query);

}

/**
 * When tracking which stations have already had springcasting events, there's
 * a possibility that such an entity already exists, so in the case of bloom, which
 * must happen after leaf, thus triggers the second event, this will either 
 * create or retrieve the correct row.
 * 
 * Returns the id of the springcasting table that is created or retreived. 
 * Returns null if the function fails.
 * 
 * @param int $station_id
 * @param PDO $mysql
 * @return int
 */
function checkOrCreateSpringCastExists($station_id, &$mysql){
    $query = "SELECT * FROM usanpn2.Springcast WHERE Station_ID = " . $station_id . 
            " AND Year = " . YEAR;
    $id = null;
    
    $results = $mysql->getResults($query);
    
    if($row = $results->fetch()){
        $id = $row['Sprincast_ID'];
    }else{
        $query = "INSERT INTO usanpn2.Springcast (Station_ID, Year) VALUES (" . $station_id . ", " . YEAR . ")";
        $mysql->runQuery($query);
        $id = $mysql->getId();
    }
    
    return $id;
    
}



/**
 * Stub class to store information about a person/observer to contact
 */
class LilacPerson{
    var $id;
    var $email;
    var $stations;
    
    public function __construct() {
        $this->stations = array();
    }
}

/**
 * Stub class to store information about a location that has a lilac
 */
class Station{
    var $id;
    var $name;
    
}