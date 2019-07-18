<?php


/*
 * CC uses UTC to schedule campaigns so need to be sure to set that explicitely
 * here.
 */
date_default_timezone_set("UTC");





require_once 'output_file.php';
/**
 * This loads the CC API
 */
require_once 'vendor/autoload.php';

use Ctct\Components\EmailMarketing\Campaign;
use Ctct\Components\EmailMarketing\MessageFooter;
use Ctct\Services\EmailMarketingService;
use Ctct\ConstantContact;
use Ctct\Components\EmailMarketing\Schedule;


$params = parse_ini_file(__DIR__ . '/config.ini');
$log = new OutputFile(__DIR__ . "/output.txt");


define('LIST_NAME', 'Testing Buffelgrass Notification'); //This is the test list
//define('LIST_NAME', 'Buffelgrass Pheno Forecast');
define('SECRET_HASH', $params['cc_api_v3_secret_hash']);

define('EMAIL_NOTIFY', $params['email_contact']);


$access_token_v3 = fetchAccessToken();

$debug = $params['safe_mode'];


$date = new DateTime();
$campaign_name = "Weekly Bufflegrass notification email for, " . $date->format("Y-m-d H:i:s");


$campaign_body_create = '
        {
  "name": "' . $campaign_name . '",
  "email_campaign_activities": [
    {
      "format_type": 1,
      "from_name": "USA National Phenology Network",
      "from_email": "erin@usanpn.org",
      "reply_to_email": "erin@usanpn.org",
      "subject": "See where buffelgrass is green right now",
      "html_content": "' . getHTML() . '",' .      
      '"physical_address_in_footer": {
        "address_line1": "1311 E 4th St.",
        "city": "Tucson",
        "country_code": "US",
        "country_name": "United States",
        "organization_name": "USA National Phenology Network",
        "postal_code": "85719",
        "state_code": "AZ",
        "state_name": "Arizona"
      },
      "document_properties": {        
        "style_content": ".white{color: #ffffff;}",
        "greeting_salutation": "Dear",
        "greeting_name_type": "F",
        "greeting_secondary": "Greetings!",
        "letter_format": "XHTML"
      }
    }
  ]
}';

//"text_content": "' .  getTextVersion() . '",
$campaign = submitCampaignMessageV3($campaign_body_create, $access_token_v3);



/**
 * Gets the list object from the CC API, as well as comes up with the name
 * of the campaign we're going to create and schedule.
 */
$list = getCampaignListV3(LIST_NAME, $access_token_v3);


$campaign_body_update = '
{

      "format_type": 1,
      "from_name": "USA National Phenology Network",
      "from_email": "erin@usanpn.org",
      "reply_to_email": "erin@usanpn.org",
      "subject": "See where buffelgrass is green right now",
      "campaign_id": "' . $campaign->campaign_id . '",
      "campaign_activity_id": "' . $campaign->campaign_activities[0]->campaign_activity_id . '",
      "role": "primary_email",
      "current_status": "Draft",
      "contact_list_ids": ["'. $list->list_id . '"]

}';


addContactListToCampaignV3($campaign_body_update, $campaign->campaign_activities[0]->campaign_activity_id, $access_token_v3);

scheduleCampaignV3($campaign->campaign_activities[0]->campaign_activity_id, $access_token_v3);

sendEmail("BG Notification should be successfully sent", false);






function getTextVersion(){
    $current_date = new DateTime();
    $last_week = (new Datetime())->sub(new DateInterval('P7D'));
    return "Hello,world";
    /*
    return "<text>~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\\n
\\n        
*********************************\\n
\\n
Buffelgrass Pheno Forecast\\n
\\n
*********************************\\n
\\n
 \\n
\\n
See the Buffelgrass Phenoforecast for where to expect green now:\\n
\\n
" . 'https://www.usanpn.org/files/npn/maps/buffelgrass-' . $last_week->format('Y-m-d') . '.png' . "\\n
\\n
 \\n
\\n
See the Buffelgrass Phenoforecast for where to expect green in 1-2 weeks:\\n
\\n
" . 'https://www.usanpn.org/files/npn/maps/buffelgrass-' . $current_date->format('Y-m-d') . '.png' . "\\n
\\n
\\n
\\n
Research has indicated that for some sites, 1 inch of precipitation over a 24-day period is sufficient to trigger green-up, whereas in other sites a 1.7 inch threshold is more appropriate.\\n
\\n
Check for green up at your site now and let us know what you see! Report your observations in Nature's Notebook:\\n
\\n
https://www.naturesnotebook.org\\n
\\n
Or give us quick feedback:\\n
\\n
https://buffelgrass.usanpn.org\\n
\\n
\\n
\\n
Explore the forecast maps in the Visualization Tool:\\n
\\n
http://data.usanpn.org/npn-viz-tool\\n
\\n
\\n
\\n
Learn more about these forecasts:\\n
\\n
https://www.usanpn.org/data/forecasts/buffelgrass\\n
\\n
</text>";
*/
}





function sendEmail($msg, $err=false){
    $msg = wordwrap($msg, 70);
    $subject = ($err) ? "ERROR in Bufflegrass notification" : "Successful Bufflegrass notification";
    mail(EMAIL_NOTIFY, $subject, $msg);
    
}


function scheduleCampaignV3($campaign_activity_id, $access_token){
    global $log;
    $log->write("Calling scheduleCampaignV3");
    

    
    $date = new DateTime();
    $date->add(new DateInterval('PT25M'));
    
    
    /**
     * So this handles an oddity in the CC API where they only acknowledge
     * dates as being UTC but validate them based on their local timezone
     * in MA. This will basically schedule the campaign to be sent in
     * 25 minutes but subtract the appropriate number of hours from the current
     * UTC time to express that in ET, and adjust for daylight savings.
     */
    if(date('I') == 1){
        $date->sub(new DateInterval('PT3H'));
    }else{
        $date->sub(new DateInterval('PT4H'));
    }    
    
    $url = 'https://api.cc.email/v3/emails/activities/' . $campaign_activity_id . '/schedules';
    
    $body = '{
                "scheduled_date": "' . $date->format('Y-m-d\TH:i:s') . '"
             }'; 
    
    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Cache-Control: no-cache',
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        
        $res = curl_exec($ch);
        curl_close($ch);      
        
    } catch (Exception $ex) {
        $log->write("Failed at scheduling a v3 campaign message.");
        $log->write(print_r($ex, true));
        $log->write("FINISH CC ERROR");
        sendEmail("There was a problem in the CC buffelgrass script, scheduling the campaign. Check logs for more info."); 
    }
}

function submitCampaignMessageV3($campaign_body, $access_token){
    global $log;
    $log->write("Calling getCampaignListV3");

    $url = 'https://api.cc.email/v3/emails';
    
    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Cache-Control: no-cache',
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $campaign_body);
        
        $res = curl_exec($ch);
        curl_close($ch);        
        
    }catch(Exception $ex){
        $log->write("Failed at submitting a v3 campaign message.");
        $log->write(print_r($ex, true));
        $log->write("FINISH CC ERROR");
        sendEmail("There was a problem in the CC buffelgrass script, creating the campaign. Check logs for more info.");
    }
    
    return json_decode($res);
    
}

function addContactListToCampaignV3($campaign_body_update, $campaign_activity_id, $access_token){
        
    global $log;
    $log->write("Calling addContactListToCampaignV3");
    

    $url = 'https://api.cc.email/v3/emails/activities/' . $campaign_activity_id;
    
    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Cache-Control: no-cache',
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ));
       curl_setopt($ch, CURLOPT_POSTFIELDS,$campaign_body_update);
        
        $res = curl_exec($ch);
        curl_close($ch);        
        
    }catch(Exception $ex){
        $log->write("Failed at adding list to campaign.");
        $log->write(print_r($ex, true));
        $log->write("FINISH CC ERROR");
        sendEmail("There was a problem in the CC buffelgrass script, adding the contact list to the campaign. Check logs for more info.");        
    }
    
    return $res;

}


function getCampaignListV3($list_name, $access_token){
    
    global $log;
    $log->write("Calling getCampaignListV3");
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
        sendEmail("There was a problem in the CC buffelgrass script, GETing the contact list. Check logs for more info.");
    }
    
    return $the_list;
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
        sendEmail("There was a problem in the CC buffelgrass script, getting an access token. Check logs for more info.");        
        die();
    }
    
    saveRefreshToken($new_refresh_token);
    
    return $access_token;
}


function getHTML(){
    $current_date = new DateTime();
    $last_week = (new Datetime())->sub(new DateInterval('P7D'));
return "
<html>
<head>

<!--[if gte mso 9]>
<style id=\\\"ol-styles\\\">
/* OUTLOOK-SPECIFIC STYLES */
li {
text-indent: -1em;
padding: 0;
margin: 0;
line-height: 1.2;
}
ul, ol {
padding: 0;
margin: 0 0 0 40px;
}
p {
margin: 0;
padding: 0;
margin-bottom: 0;
}
sup {
font-size: 85% !important;
}
sub {
font-size: 85% !important;
}
</style>
<![endif]-->
<style id=\\\"template-styles-head\\\" data-premailer=\\\"ignore\\\">
.footer-main-width {
width: 590px!important;
max-width: 590px;
}
table {
border-collapse: collapse;
table-layout: fixed;
}
.bgimage {
table-layout: auto;
}
.preheader-container {
color: transparent;
display: none;
font-size: 1px;
line-height: 1px;
max-height: 0px;
max-width: 0px;
opacity: 0;
overflow: hidden;
}
/* LIST AND p STYLE OVERRIDES */
.editor-text p {
margin: 0;
padding: 0;
margin-bottom: 0;
}
.editor-text ul,
.editor-text ol {
padding: 0;
margin: 0 0 0 40px;
}
.editor-text li {
padding: 0;
margin: 0;
line-height: 1.2;
}
/* ==================================================
CLIENT/BROWSER SPECIFIC OVERRIDES
================================================== */
/* IE: correctly scale images with w/h attbs */
img {
-ms-interpolation-mode: bicubic;
}
/* Text Link Style Reset */
a {
text-decoration: underline;
}
/* iOS: Autolink styles inherited */
a[x-apple-data-detectors] {
text-decoration: underline !important;
font-size: inherit !important;
font-family: inherit !important;
font-weight: inherit !important;
line-height: inherit !important;
color: inherit !important;
}
/* FF/Chrome: Smooth font rendering */
.editor-text, .MainTextFullWidth {
-webkit-font-smoothing: antialiased;
-moz-osx-font-smoothing: grayscale;
}
/* Gmail/Web viewport fix */
u + .body .template-body {
width: 590px;
}
@media only screen and (max-width:480px) {
u + .body .template-body {
width: 100% !important;
}
}
/* Office365/Outlook.com image reset */
[office365] button, [office365] .divider-base div, [office365] .spacer-base div, [office365] .editor-image div { display: block !important; }
</style>
<style>@media only screen and (max-width:480px) {
table {
border-collapse: collapse;
}
.main-width {
width: 100% !important;
}
.mobile-hidden {
display: none !important;
}
td.OneColumnMobile {
display: block !important;
}
.OneColumnMobile {
width: 100% !important;
}
td.editor-col .editor-text {
padding-left: 20px !important; padding-right: 20px !important;
}
td.editor-col .editor-image.editor-image-hspace-on td {
padding-left: 20px !important; padding-right: 20px !important;
}
td.editor-col .editor-button-container {
padding-left: 20px !important; padding-right: 20px !important;
}
td.editor-col .editor-social td {
padding-left: 20px !important; padding-right: 20px !important;
}
td.editor-col .block-margin {
padding-left: 20px !important; padding-right: 20px !important;
}
td.editor-col td.block-margin .editor-text {
padding-left: 0px !important; padding-right: 0px !important;
}
td.editor-col td.block-margin .editor-image.editor-image-hspace-on td {
padding-left: 0px !important; padding-right: 0px !important;
}
td.editor-col td.block-margin .editor-button-container {
padding-left: 0px !important; padding-right: 0px !important;
}
td.editor-col td.block-margin .editor-social td {
padding-left: 0px !important; padding-right: 0px !important;
}
.editor-button td > table tr > td {
padding: 0px 0px 0px 0px !important;
}
.editor-button td > table tr > td td {
padding: 9px 15px 10px 15px !important;
}
.layout {
padding: 15px 9px 15px 9px !important;
}
.layout-container-border {
padding: 0px 0px 0px 0px !important;
}
.layout-container {
padding: 0px 0px 0px 0px !important;
}
.editor-image img {
width: auto !important; margin-left: auto !important; margin-right: auto !important;
}
.editor-image .image-cell {
padding-bottom: 15px !important;
}
.editor-text {
font-size: 16px !important;
}
.section-headline-text {
font-size: 24px !important;
}
.headline-text {
font-size: 24px !important;
}
.subheadline-text {
font-size: 20px !important;
}
.feature {
padding-top: 0px !important; padding-bottom: 0px !important;
}
.layout-outer {
padding: 0px 20px !important;
}
.feature-heading-text {
font-size: 20px !important;
}
.feature-text {
font-size: 16px !important;
}
.split.editor-col {
margin-top: 0px !important;
}
.split.editor-col ~ .split.editor-col {
margin-top: 10px !important;
}
.split-layout-margin {
padding: 0px 20px !important;
}
.article {
padding-top: 0px !important; padding-bottom: 0px !important;
}
.article-heading-text {
font-size: 20px !important;
}
.article-text {
font-size: 16px !important;
}
.social-container {
text-align: center !important;
}
.social-text {
font-size: 14px !important;
}
.cpn-heading-text {
font-size: 28px !important;
}
.editor-cpn-heading-text {
font-size: 28px !important;
}
td.col-divided .editor-col {
border-right: 0px solid #99DDDC !important; border-bottom: 1px solid #99DDDC !important;
}
td.col-divided td.editor-col:last-of-type {
border-bottom: 0 !important;
}
.col-divided {
padding: 0 20px !important;
}
td.col-divided .editor-col .editor-text {
padding-left: 0px !important; padding-right: 0px !important;
}
td.col-divided .editor-col .editor-image.editor-image-hspace-on td {
padding-left: 0px !important; padding-right: 0px !important;
}
td.col-divided .editor-col .editor-button-container {
padding-left: 0px !important; padding-right: 0px !important;
}
td.col-divided .editor-col .editor-social td {
padding-left: 0px !important; padding-right: 0px !important;
}
td.col-divided .editor-col .block-margin {
padding-left: 0px !important; padding-right: 0px !important;
}
.action-block .poll-answer {
width: 100% !important; display: block !important;
}
.action-block .poll-button {
width: 100% !important;
}
div.MobileFooter {
font-size: 11px !important;
}
td.FooterMobile {
padding: 0px 10px 0px 10px !important;
}
td.MainCenter {
width: 100% !important;
}
table.MainSide {
display: none !important;
}
img.MainSide {
display: none !important;
}
td.MainSide {
display: none !important;
}
.rsvp-button-inner {
padding: 0px 0px 10px 0px !important;
}
.rsvp-button-outer {
width: 100% !important; max-width: 100% !important;
}
.action-block .poll-answer {
width: 100% !important; display: block !important;
}
.action-block .poll-button {
width: 100% !important;
}
div.MobileFooter {
font-size: 11px !important;
}
td.FooterMobile {
padding: 0px 10px 0px 10px !important;
}
td.MainCenter {
width: 100% !important;
}
table.MainSide {
display: none !important;
}
img.MainSide {
display: none !important;
}
td.MainSide {
display: none !important;
}
.rsvp-button-inner {
padding: 0px 0px 10px 0px !important;
}
.rsvp-button-outer {
width: 100% !important; max-width: 100% !important;
}
.action-block .poll-answer {
width: 100% !important; display: block !important;
}
.action-block .poll-button {
width: 100% !important;
}
div.MobileFooter {
font-size: 11px !important;
}
td.FooterMobile {
padding: 0px 10px 0px 10px !important;
}
td.MainCenter {
width: 100% !important;
}
table.MainSide {
display: none !important;
}
img.MainSide {
display: none !important;
}
td.MainSide {
display: none !important;
}
.rsvp-button-inner {
padding: 0px 0px 10px 0px !important;
}
.rsvp-button-outer {
width: 100% !important; max-width: 100% !important;
}
.cpn-heading-text {
font-size: 28px !important;
}
.editor-cpn-heading-text {
font-size: 28px !important;
}
.action-block .poll-answer {
width: 100% !important; display: block !important;
}
.action-block .poll-button {
width: 100% !important;
}
div.MobileFooter {
font-size: 11px !important;
}
td.FooterMobile {
padding: 0px 10px 0px 10px !important;
}
td.MainCenter {
width: 100% !important;
}
table.MainSide {
display: none !important;
}
img.MainSide {
display: none !important;
}
td.MainSide {
display: none !important;
}
.rsvp-button-inner {
padding: 0px 0px 10px 0px !important;
}
.rsvp-button-outer {
width: 100% !important; max-width: 100% !important;
}
.footer-main-width {
width: 100% !important;
}
.footer-mobile-hidden {
display: none !important;
}
.footer-mobile-hidden {
display: none !important;
}
.footer-column {
display: block !important;
}
.footer-mobile-stack {
display: block !important;
}
.footer-mobile-stack-padding {
padding-top: 3px;
}
}
@media only screen and (max-width:320px) {
.layout {
padding: 0px 0px 0px 0px !important;
}
}
@media screen {
@font-face {
font-family: ''; font-style: normal; font-weight: 400; src: local(''), local(''), url() format(''); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02C6, U+02DA, U+02DC, U+2000-206F, U+2074, U+20AC, U+2212, U+2215;
}
}
</style>
</head>
<body class=\\\"body\\\" align=\\\"center\\\" style=\\\"width: 100%; min-width: 100%; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; background-color: #FFFFFF; margin: 0px; padding: 0px;\\\" bgcolor=\\\"#FFFFFF\\\">

     <table class=\\\"template-body\\\" border=\\\"0\\\" cellpadding=\\\"0\\\" cellspacing=\\\"0\\\" style=\\\"text-align: center; min-width: 100%;\\\" width=\\\"100%\\\">
        <tr>
            <td class=\\\"preheader-container\\\">
                <div>
                    <div id=\\\"preheader\\\" style=\\\"display: none; font-size: 1px; color: transparent; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;\\\">
                        <span data-entity-ref=\\\"preheader\\\"></span>
                    </div>

                </div>
            </td>
        </tr>
        <tr>
            <td class=\\\"template-shell-container\\\" align=\\\"center\\\">
                <div class=\\\"bgcolor\\\" style=\\\"background-color: #FFFFFF;\\\">
                    <!--[if gte mso 9]>
<v:background xmlns:v=\\\"urn:schemas-microsoft-com:vml\\\" fill=\\\"t\\\">
<v:fill type=\\\"tile\\\" src=\\\"https://imgssl.constantcontact.com/letters/images/backgrounds/sparklesbasic.png\\\" color=\\\"#FFFFFF\\\" ></v:fill>
</v:background>
<![endif]-->
                    <table class=\\\"bgimage\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"background: url('https://imgssl.constantcontact.com/letters/images/backgrounds/sparklesbasic.png') repeat top left;\\\" background=\\\"https://imgssl.constantcontact.com/letters/images/backgrounds/sparklesbasic.png\\\">
                        <tbody>
                            <tr>
                                <td align=\\\"center\\\">
                                    <table class=\\\"main-width\\\" width=\\\"590\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" align=\\\"center\\\" style=\\\"width: 590px;\\\">
                                        <tbody>
                                            <tr>
                                                <td class=\\\"layout\\\" valign=\\\"top\\\" align=\\\"center\\\" style=\\\"padding: 15px 5px;\\\">
                                                    <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" align=\\\"center\\\">
                                                        <tbody>
                                                            <tr>
                                                                <td class=\\\"layout-container-border\\\" valign=\\\"top\\\" align=\\\"center\\\" style=\\\"background-color: #99DDDC; padding: 0px;\\\" bgcolor=\\\"#99DDDC\\\">
                                                                    <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" align=\\\"center\\\" style=\\\"background-color: #99DDDC;\\\" bgcolor=\\\"#99DDDC\\\">
                                                                        <tbody>
                                                                            <tr>
                                                                                <td class=\\\"layout-container\\\" valign=\\\"top\\\" align=\\\"center\\\" style=\\\"background-color: #ffffff; padding: 0;\\\" bgcolor=\\\"#ffffff\\\">
                                                                                    <div class=\\\"\\\">
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\"spacer editor-col OneColumnMobile\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"left\\\">
                                                                                                        <div class=\\\"gl-contains-spacer\\\">
                                                                                                            <table class=\\\"editor-spacer\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"spacer-container\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                                <tbody>
                                                                                                                                    <tr>
                                                                                                                                        <td class=\\\"spacer-base\\\" style=\\\"padding-bottom: 30px; height: 1px; line-height: 1px;\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                                            <div><img alt=\\\"\\\" src=\\\"https://imgssl.constantcontact.com/letters/images/sys/S.gif\\\" width=\\\"5\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" height=\\\"1\\\" border=\\\"0\\\" style=\\\"display: block; height: 1px; width: 5px;\\\" /></div>
                                                                                                                                        </td>
                                                                                                                                    </tr>
                                                                                                                                </tbody>
                                                                                                                            </table>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\" editor-col OneColumnMobile\\\" style=\\\"background-color: rgb(57, 66, 52);\\\" width=\\\"50%\\\" valign=\\\"top\\\" align=\\\"\\\" bgcolor=\\\"394234\\\">
                                                                                                        <div>
                                                                                                            <div class=\\\"column-resize-bar\\\">
                                                                                                                <span class=\\\"line\\\"></span>
                                                                                                                <span class=\\\"grabber\\\"></span>
                                                                                                            </div>
                                                                                                        </div>
                                                                                                        <div class=\\\"gl-contains-text\\\">
                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-text editor-text \\\" style=\\\"line-height: 1; font-family: Trebuchet MS, Verdana, Helvetica, sans-serif; font-size: 14px; color: #384856; text-align: left; display: block; word-wrap: break-word; padding: 10px 10px 10px 20px;\\\" valign=\\\"top\\\" align=\\\"left\\\">
                                                                                                                            <div></div>
                                                                                                                            <div class=\\\"text-container galileo-ap-content-editor\\\">
                                                                                                                                <div>
                                                                                                                                    <div>
                                                                                                                                        <br />
                                                                                                                                    </div>
                                                                                                                                    <div>
                                                                                                                                        <br />
                                                                                                                                    </div>
                                                                                                                                    <div><span style=\\\"font-size: 26px; color: rgb(255, 255, 255); font-family: Century Gothic, Calibri, Helvetica, Arial, sans-serif;\\\">Buffelgrass </span></div>
                                                                                                                                    <div><span style=\\\"font-size: 26px; color: rgb(255, 255, 255); font-family: Century Gothic, Calibri, Helvetica, Arial, sans-serif;\\\">Pheno Forecast</span></div>
                                                                                                                                </div>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                    <td class=\\\" editor-col OneColumnMobile\\\" style=\\\"background-color: rgb(57, 66, 52);\\\" width=\\\"50%\\\" valign=\\\"top\\\" align=\\\"\\\" bgcolor=\\\"394234\\\">
                                                                                                        <div class=\\\"gl-contains-image\\\">
                                                                                                            <table class=\\\"editor-image logo-container\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td valign=\\\"top\\\" align=\\\"center\\\" style=\\\"padding-top: 0px; padding-bottom: 0px;\\\">
                                                                                                                            <div class=\\\"publish-container\\\"> <img alt=\\\"\\\" class=\\\"\\\" style=\\\"display: block; height: auto; max-width: 100%;\\\" src=\\\"https://files.constantcontact.com/a532cefb001/6b413e97-dbd6-4bf0-9a6d-0e0f66eea4d5.png\\\" width=\\\"135\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" border=\\\"0\\\" />
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\" editor-col OneColumnMobile\\\" style=\\\"background-color: rgb(71, 155, 70);\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"\\\" bgcolor=\\\"479B46\\\">
                                                                                                        <div class=\\\"gl-contains-text\\\">
                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-text editor-text \\\" valign=\\\"top\\\" align=\\\"left\\\" style=\\\"font-family: Trebuchet MS, Verdana, Helvetica, sans-serif; font-size: 14px; color: #384856; text-align: left; display: block; word-wrap: break-word; line-height: 1.2; padding: 10px 20px;\\\">
                                                                                                                            <div></div>
                                                                                                                            <div class=\\\"text-container galileo-ap-content-editor\\\">
                                                                                                                                <div>
                                                                                                                                    <div><span style=\\\"font-size: 20px; color: rgb(255, 255, 255);\\\">Where to expect green now</span></div>
                                                                                                                                </div>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\"article editor-col OneColumnMobile\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"left\\\">
                                                                                                        <div class=\\\"gl-contains-image\\\">
                                                                                                            <table class=\\\"editor-image  editor-image-vspace-on\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td valign=\\\"top\\\" align=\\\"center\\\" style=\\\"padding-top: 10px; padding-bottom: 10px;\\\">
                                                                                                                            <div class=\\\"publish-container\\\"> <img alt=\\\"basicImage\\\" class=\\\"\\\" style=\\\"display: block; height: auto !important; max-width: 100% !important;width:100%;margin-top:20px !important\\\" src=\\\"" . 'https://www.usanpn.org/files/npn/maps/buffelgrass-' . $last_week->format('Y-m-d') . '.png' . "\\\" width=\\\"275\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" border=\\\"0\\\" />
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                        <div class=\\\"gl-contains-text\\\">
                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-text article-text\\\" valign=\\\"top\\\" align=\\\"left\\\" style=\\\"font-family: Trebuchet MS, Verdana, Helvetica, sans-serif; font-size: 14px; color: #384856; text-align: left; display: block; word-wrap: break-word; line-height: 1.2; padding: 10px 20px;\\\">
                                                                                                                            <div></div>
                                                                                                                            <div class=\\\"text-container galileo-ap-content-editor\\\">
                                                                                                                                <div>
                                                                                                                                    <div><span style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif;color:#384856\\\">Research has indicated that for some sites, 1 inch of precipitation over a 24-day period is sufficient to trigger green-up, whereas in other sites a 1.7 inch threshold is more appropriate. </span></div>
                                                                                                                                    <div>
                                                                                                                                        <br />
                                                                                                                                    </div>
                                                                                                                                    <div>
                                                                                                                                        <span style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif;\\\">Did we get it right at your site? Give us quick feedback via our <SimpleURLProperty name=\\\"simple-data-entry\\\" track=\\\"true\\\" label=\\\"4-question form\\\" href=\\\"https://buffelgrass.usanpn.org\\\"><a href = 'https://buffelgrass.usanpn.org/' target=\\\"_blank\\\" style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif; color: rgb(71, 155, 70); text-decoration: none; font-weight: bold;\\\"><span style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif; color: rgb(71, 155, 70); font-weight: bold;\\\">4-question form</span></a></SimpleURLProperty>, or register your site in <SimpleURLProperty name=\\\"Natures-Notebook\\\" track=\\\"true\\\" label=\\\"Nature's Notebook\\\" href=\\\"https://www.usanpn.org/natures_notebook\\\"><a href=\\\"https://www.usanpn.org/natures_notebook\\\" target=\\\"_blank\\\" style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif; color: rgb(71, 155, 70); text-decoration: none; font-weight: bold; font-style: italic;\\\">Nature's Notebook</a></SimpleURLProperty> and record your long-term observations.</span>
                                                                                                                                    </div>
                                                                                                                                    <div><span style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif;\\\">&#xfeff;</span></div>
                                                                                                                                </div>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>

                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\" editor-col OneColumnMobile\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"\\\">
                                                                                                        <div class=\\\"gl-contains-spacer\\\">
                                                                                                            <table class=\\\"editor-spacer\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                                <tbody>
                                                                                                                                    <tr>
                                                                                                                                        <td class=\\\"spacer-base\\\" style=\\\"padding-bottom: 30px; height: 1px; line-height: 1px;\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                                            <div><img alt=\\\"\\\" src=\\\"https://imgssl.constantcontact.com/letters/images/1101116784221/S.gif\\\" width=\\\"5\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" height=\\\"1\\\" border=\\\"0\\\" style=\\\"display: block; height: 1px; width: 5px;\\\" /></div>
                                                                                                                                        </td>
                                                                                                                                    </tr>
                                                                                                                                </tbody>
                                                                                                                            </table>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\" editor-col OneColumnMobile\\\" style=\\\"background-color: rgb(71, 155, 70);\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"\\\" bgcolor=\\\"479B46\\\">
                                                                                                        <div class=\\\"gl-contains-text\\\">
                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-text editor-text \\\" valign=\\\"top\\\" align=\\\"left\\\" style=\\\"font-family: Trebuchet MS, Verdana, Helvetica, sans-serif; font-size: 14px; color: #384856; text-align: left; display: block; word-wrap: break-word; line-height: 1.2; padding: 10px 20px;\\\">
                                                                                                                            <div></div>
                                                                                                                            <div class=\\\"text-container galileo-ap-content-editor\\\">
                                                                                                                                <div>
                                                                                                                                    <div><span style=\\\"font-size: 20px; color: rgb(255, 255, 255);\\\">Where to expect green in 1-2 weeks</span></div>
                                                                                                                                </div>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\"article editor-col OneColumnMobile\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"left\\\">
                                                                                                        <div class=\\\"gl-contains-image\\\">
                                                                                                            <table class=\\\"editor-image  editor-image-vspace-on\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td valign=\\\"top\\\" align=\\\"center\\\" style=\\\"padding-top: 10px; padding-bottom: 10px;\\\">
                                                                                                                            <div class=\\\"publish-container\\\"> <img alt=\\\"basicImage\\\" class=\\\"\\\" style=\\\"display: block; height: auto !important; max-width: 100% !important;width:100%;margin-top:20px !important\\\" src=\\\"" . 'https://www.usanpn.org/files/npn/maps/buffelgrass-' . $current_date->format('Y-m-d') . '.png'  . "\\\" width=\\\"275\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" border=\\\"0\\\" />
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>

                                                                                                        <div class=\\\"gl-contains-text\\\">
                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-text \\\" valign=\\\"top\\\" align=\\\"left\\\" style=\\\"font-family: Trebuchet MS, Verdana, Helvetica, sans-serif; font-size: 14px; color: #384856; text-align: left; display: block; word-wrap: break-word; line-height: 1.2; padding: 10px 20px;\\\">
                                                                                                                            <div></div>
                                                                                                                            <div class=\\\"text-container galileo-ap-content-editor\\\">
                                                                                                                                <div>
                                                                                                                                    <div>
                                                                                                                                        <span style=\\\"font-size: 16px; color: rgb(56, 72, 86); font-family: Calibri, Helvetica, Arial, sans-serif;\\\">Let us know what you see at your site - give us <SimpleURLProperty name=\\\"quick-feedback\\\" track=\\\"true\\\" label=\\\"quick feedback\\\" href=\\\"https://buffelgrass.usanpn.org\\\"><a href = 'https://buffelgrass.usanpn.org/' target=\\\"_blank\\\" style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif; color: rgb(71, 155, 70); text-decoration: none; font-weight: bold;\\\"><span style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif; color: rgb(71, 155, 70); font-weight: bold;\\\">quick feedback</span></a></SimpleURLProperty> or report your observations in </span><SimpleURLProperty name=\\\"Nature-Notebook-2\\\" track=\\\"true\\\" label=\\\"Natures Notebook\\\" href=\\\"https://www.usanpn.org/natures_notebook\\\"><a href=\\\"https://www.usanpn.org/natures_notebook\\\" target=\\\"_blank\\\" style=\\\"font-size: 16px; color: rgb(71, 155, 70); font-family: Calibri, Helvetica, Arial, sans-serif; text-decoration: none; font-style: italic; font-weight: bold;\\\">Nature&#x2019;s Notebook</a></SimpleURLProperty><span style=\\\"font-size: 16px; font-family: Calibri, Helvetica, Arial, sans-serif;\\\"></span>.
                                                                                                                                    </div>
                                                                                                                                </div>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>

                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\" editor-col OneColumnMobile\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"\\\">
                                                                                                        <div class=\\\"gl-contains-button\\\">
                                                                                                            <table class=\\\"editor-button\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"width: 100%; min-width: 100%;\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-button-container \\\" style=\\\"font-family: Arial, Verdana, Helvetica, sans-serif; font-size: 14px; font-weight: bold; color: #1A75BB; text-decoration: none; padding: 10px 20px;\\\">
                                                                                                                            <table class=\\\"galileo-ap-content-editor\\\" style=\\\"width: 100%; min-width: 100%;\\\">
                                                                                                                                <tbody>
                                                                                                                                    <tr>
                                                                                                                                        <td class=\\\"MainTextFullWidthTD\\\" valign=\\\"top\\\" align=\\\"center\\\" style=\\\"font-family: Arial, Verdana, Helvetica, sans-serif; font-size: 14px; font-weight: bold; color: #1A75BB; text-decoration: none; padding: 0px;\\\">
                                                                                                                                            <table style=\\\"background-color: rgb(57, 66, 52); width: initial; moz-border-radius: 0px; border-radius: 0px; border-spacing: 0; min-width: initial; padding: 0; border: none;\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" bgcolor=\\\"394234\\\">
                                                                                                                                                <tbody>
                                                                                                                                                    <tr>
                                                                                                                                                        <td class=\\\"MainTextFullWidthTD\\\" valign=\\\"top\\\" align=\\\"center\\\" style=\\\"font-family: Arial, Verdana, Helvetica, sans-serif; font-size: 14px; font-weight: bold; color: #1A75BB; text-decoration: none; padding: 9px 15px 10px;\\\">
                                                                                                                                                            <div>
                                                                                                                                                                <div class=\\\"MainTextFullWidth\\\"><SimpleURLProperty name=\\\"viz-tool\\\" track=\\\"true\\\" label=\\\"Explore the forecast maps in the Visualization Tool (click the bug icon on the left and select Buffelgrass)\\\" href=\\\"https://data.usanpn.org/npn-viz-tool\\\"><a href=\\\"https://data.usanpn.org/npn-viz-tool\\\" style=\\\"color: rgb(255, 255, 255); font-size: 16px; font-family: Arial, Verdana, Helvetica, sans-serif; font-weight: bold; text-decoration: none;\\\">Explore the forecast maps in the Visualization Tool (click the bug icon on the left and select Buffelgrass)</a></SimpleURLProperty></div>
                                                                                                                                                            </div>
                                                                                                                                                        </td>
                                                                                                                                                    </tr>
                                                                                                                                                </tbody>
                                                                                                                                            </table>
                                                                                                                                        </td>
                                                                                                                                    </tr>
                                                                                                                                </tbody>
                                                                                                                            </table>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\" editor-col OneColumnMobile\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"\\\">
                                                                                                        <div class=\\\"gl-contains-text\\\">
                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-text editor-text \\\" valign=\\\"top\\\" align=\\\"left\\\" style=\\\"font-family: Trebuchet MS, Verdana, Helvetica, sans-serif; font-size: 14px; color: #384856; text-align: left; display: block; word-wrap: break-word; line-height: 1.2; padding: 10px 20px;\\\">
                                                                                                                            <div></div>
                                                                                                                            <div class=\\\"text-container galileo-ap-content-editor\\\">
                                                                                                                                <div>
                                                                                                                                    <div><SimpleURLProperty name=\\\"landing-page\\\" track=\\\"true\\\" label=\\\"Learn more about these forecasts\\\" href=\\\"https://www.usanpn.org/data/forecasts/buffelgrass\\\"><a href=\\\"https://www.usanpn.org/data/forecasts/buffelgrass\\\" target=\\\"_blank\\\" style=\\\"color: rgb(71, 155, 70); text-decoration: none; font-weight: bold; font-style: normal;\\\">Learn more about these forecasts &#xbb;</a></SimpleURLProperty></div>
                                                                                                                                </div>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>

                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\" editor-col OneColumnMobile\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"\\\">
                                                                                                        <div class=\\\"gl-contains-spacer\\\">
                                                                                                            <table class=\\\"editor-spacer\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                                <tbody>
                                                                                                                                    <tr>
                                                                                                                                        <td class=\\\"spacer-base\\\" style=\\\"padding-bottom: 30px; height: 1px; line-height: 1px;\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                                            <div><img alt=\\\"\\\" src=\\\"https://imgssl.constantcontact.com/letters/images/1101116784221/S.gif\\\" width=\\\"5\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" height=\\\"1\\\" border=\\\"0\\\" style=\\\"display: block; height: 1px; width: 5px;\\\" /></div>
                                                                                                                                        </td>
                                                                                                                                    </tr>
                                                                                                                                </tbody>
                                                                                                                            </table>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>

                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\"about editor-col OneColumnMobile\\\" style=\\\"background-color: rgb(57, 66, 52);\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"left\\\" bgcolor=\\\"394234\\\">
                                                                                                        <div class=\\\"gl-contains-text\\\">
                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-text about-text\\\" valign=\\\"top\\\" align=\\\"left\\\" style=\\\"font-family: Trebuchet MS, Verdana, Helvetica, sans-serif; font-size: 14px; color: #384856; text-align: center; display: block; word-wrap: break-word; line-height: 1.2; padding: 10px 20px;\\\">
                                                                                                                            <div>
                                                                                                                                <table class=\\\"editor-image OneColumnMobile\\\" style=\\\"mso-table-rspace: 5.75pt;\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" align=\\\"right\\\">
                                                                                                                                    <tbody>
                                                                                                                                        <tr>
                                                                                                                                            <td class=\\\"mobile-hidden\\\" style=\\\"height: 1px; line-height: 1px; padding: 0px;\\\" width=\\\"15\\\" valign=\\\"top\\\" height=\\\"1\\\" align=\\\"center\\\">
                                                                                                                                                <img alt=\\\"\\\" src=\\\"https://imgssl.constantcontact.com/letters/images/sys/S.gif\\\" width=\\\"15\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" height=\\\"1\\\" border=\\\"0\\\" style=\\\"display: block; height: auto; max-width: 100%;\\\" />
                                                                                                                                            </td>
                                                                                                                                            <td class=\\\"image-cell \\\" style=\\\"padding: 0px;\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                                                <div class=\\\"publish-container\\\"> <img alt=\\\"\\\" class=\\\"\\\" style=\\\"display: block; height: auto !important; max-width: 100% !important;\\\" src=\\\"https://files.constantcontact.com/a532cefb001/07cb5133-96bf-4a42-8d11-d18e7ebf6d93.png\\\" width=\\\"258\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" border=\\\"0\\\" />
                                                                                                                                                </div>
                                                                                                                                            </td>
                                                                                                                                        </tr>
                                                                                                                                        <tr>
                                                                                                                                            <td class=\\\"mobile-hidden\\\" style=\\\"height: 1px; line-height: 1px; padding: 0px;\\\" width=\\\"5\\\" valign=\\\"top\\\" height=\\\"5\\\" align=\\\"center\\\">
                                                                                                                                                <img alt=\\\"\\\" src=\\\"https://imgssl.constantcontact.com/letters/images/sys/S.gif\\\" width=\\\"5\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" height=\\\"1\\\" border=\\\"0\\\" style=\\\"display: block; height: auto; max-width: 100%;\\\" />
                                                                                                                                            </td>
                                                                                                                                            <td class=\\\"mobile-hidden\\\" style=\\\"height: 5px; line-height: 1px; padding: 0px;\\\" valign=\\\"top\\\" height=\\\"5\\\" align=\\\"center\\\">
                                                                                                                                                <img alt=\\\"\\\" src=\\\"https://imgssl.constantcontact.com/letters/images/sys/S.gif\\\" width=\\\"1\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" height=\\\"5\\\" border=\\\"0\\\" style=\\\"display: block; height: auto; max-width: 100%;\\\" />
                                                                                                                                            </td>
                                                                                                                                        </tr>
                                                                                                                                    </tbody>
                                                                                                                                </table>
                                                                                                                            </div>
                                                                                                                            <div class=\\\"text-container galileo-ap-content-editor\\\">
                                                                                                                                <div>
                                                                                                                                    <div style=\\\"text-align: left;\\\" align=\\\"left\\\"><span style=\\\"font-size: 16px; color: rgb(255, 255, 255); font-family: Calibri, Helvetica, Arial, sans-serif; font-weight: bold;\\\">Contact</span></div>
                                                                                                                                    <div style=\\\"text-align: left;\\\" align=\\\"left\\\"><span style=\\\"font-size: 14px; color: rgb(255, 255, 255); font-family: Calibri, Helvetica, Arial, sans-serif;\\\">Kathy Gerst</span></div>
                                                                                                                                    <div style=\\\"text-align: left;\\\" align=\\\"left\\\"><a href=\\\"mailto:kathy@usanpn.org\\\" target=\\\"_blank\\\" style=\\\"font-size: 14px; color: rgb(255, 255, 255); text-decoration: none; font-weight: bold; font-family: Calibri, Helvetica, Arial, sans-serif; font-style: normal;\\\">kathy@usanpn.org</a></div>
                                                                                                                                    <div style=\\\"text-align: left;\\\" align=\\\"left\\\"><span style=\\\"font-size: 14px; color: rgb(255, 255, 255); font-family: Calibri, Helvetica, Arial, sans-serif;\\\">520-621-1740</span></div>
                                                                                                                                    <div style=\\\"text-align: left;\\\" align=\\\"left\\\"><a href=\\\"https://www.usanpn.org/about/staff#kathy\\\" target=\\\"_blank\\\" style=\\\"font-size: 14px; color: rgb(255, 255, 255); text-decoration: none; font-family: Calibri, Helvetica, Arial, sans-serif; font-weight: bold; font-style: normal;\\\">bio</a></div>
                                                                                                                                </div>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\"social editor-col OneColumnMobile\\\" style=\\\"background-color: rgb(242, 242, 242);\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"left\\\" bgcolor=\\\"F2F2F2\\\">
                                                                                                        <div class=\\\"gl-contains-text\\\">
                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"editor-text social-text\\\" valign=\\\"top\\\" align=\\\"left\\\" style=\\\"font-family: Trebuchet MS, Verdana, Helvetica, sans-serif; font-size: 12px; color: #384856; text-align: center; display: block; word-wrap: break-word; line-height: 1.2; text-decoration: none; padding: 10px 20px;\\\">
                                                                                                                            <div></div>
                                                                                                                            <div class=\\\"text-container galileo-ap-content-editor\\\">
                                                                                                                                <div>
                                                                                                                                    <div>Share with a friend</div>
                                                                                                                                </div>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                        <div class=\\\"gl-contains-social-button\\\">
                                                                                                            <table class=\\\"editor-social\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"width: 100%; min-width: 100%;\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\" social-container social-container\\\" align=\\\"center\\\" style=\\\"padding: 0px 20px 10px;\\\">
                                                                                                                            <div class=\\\"galileo-ap-content-editor\\\">
                                                                                                                                <A HREF=\\\"https://knowledgebase.constantcontact.com/articles/KnowledgeBase/22841-make-it-easy-for-contacts-to-share-an-email-socially-using-icons\\\" style=\\\"text-decoration: none;\\\">
                                                                                                                                    <img alt=\\\"Facebook\\\" src=\\\"https://imgssl.constantcontact.com/galileo/images/templates/Galileo-SocialMedia/FB_Share.png\\\" style=\\\"display: inline-block; margin: 0; padding: 0;\\\" width=\\\"100\\\" border=\\\"0\\\" /> &#x200c;
                                                                                                                                </A>
                                                                                                                                <A HREF=\\\"https://knowledgebase.constantcontact.com/articles/KnowledgeBase/22841-make-it-easy-for-contacts-to-share-an-email-socially-using-icons\\\" style=\\\"text-decoration: none;\\\">
                                                                                                                                    <img alt=\\\"Twitter\\\" src=\\\"https://imgssl.constantcontact.com/galileo/images/templates/Galileo-SocialMedia/Twitter_Share.png\\\" style=\\\"display: inline-block; margin: 0; padding: 0;\\\" width=\\\"100\\\" border=\\\"0\\\" /> &#x200c;
                                                                                                                                </A>
                                                                                                                                <A HREF=\\\"https://knowledgebase.constantcontact.com/articles/KnowledgeBase/22841-make-it-easy-for-contacts-to-share-an-email-socially-using-icons\\\" style=\\\"text-decoration: none;\\\">
                                                                                                                                    <img alt=\\\"LinkedIn\\\" src=\\\"https://imgssl.constantcontact.com/galileo/images/templates/Galileo-SocialMedia/LinkedIn_Share.png\\\" style=\\\"display: inline-block; margin: 0; padding: 0;\\\" width=\\\"100\\\" border=\\\"0\\\" /> &#x200c;
                                                                                                                                </A>
                                                                                                                            </div>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <table class=\\\"galileo-ap-layout-editor\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\" style=\\\"min-width: 100%;\\\">
                                                                                            <tbody>
                                                                                                <tr>
                                                                                                    <td class=\\\"spacer editor-col OneColumnMobile\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"left\\\">
                                                                                                        <div class=\\\"gl-contains-spacer\\\">
                                                                                                            <table class=\\\"editor-spacer\\\" width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                <tbody>
                                                                                                                    <tr>
                                                                                                                        <td class=\\\"spacer-container\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                            <table width=\\\"100%\\\" cellspacing=\\\"0\\\" cellpadding=\\\"0\\\" border=\\\"0\\\">
                                                                                                                                <tbody>
                                                                                                                                    <tr>
                                                                                                                                        <td class=\\\"spacer-base\\\" style=\\\"padding-bottom: 10px; height: 1px; line-height: 1px;\\\" width=\\\"100%\\\" valign=\\\"top\\\" align=\\\"center\\\">
                                                                                                                                            <div><img alt=\\\"\\\" src=\\\"https://imgssl.constantcontact.com/letters/images/sys/S.gif\\\" width=\\\"5\\\" vspace=\\\"0\\\" hspace=\\\"0\\\" height=\\\"1\\\" border=\\\"0\\\" style=\\\"display: block; height: 1px; width: 5px;\\\" /></div>
                                                                                                                                        </td>
                                                                                                                                    </tr>
                                                                                                                                </tbody>
                                                                                                                            </table>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                                                                </tbody>
                                                                                                            </table>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                    </div>
                                                                                </td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
        <tr>
            <td></td>
        </tr>
    </table>

<OpensTracking/>
</body>
</html>";    
    
    
}