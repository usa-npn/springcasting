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

use Ctct\Components\EmailMarketing\Campaign;
use Ctct\Components\EmailMarketing\MessageFooter;
use Ctct\Services\EmailMarketingService;
use Ctct\ConstantContact;
use Ctct\Components\EmailMarketing\Schedule;


define('LIST_NAME', 'Reminder to Observe - weekly');

$params = parse_ini_file('config.ini');
$log = new OutputFile(__DIR__ . "/output.txt");


$cc = new ConstantContact($params['cc_api_key']);
$cc_access_token = $params['cc_access_token'];


$debug = $params['safe_mode'];


/**
 * Gets the list object from the CC API, as well as comes up with the name
 * of the campaign we're going to create and schedule.
 */
$list = getCampaignList($cc, $cc_access_token, LIST_NAME);
$date = new DateTime();
$campaign_name = "Weekly Reminder email for, " . $date->format("Y-m-d");


/**
 * Initialize all fields in the campaign.
 */
$footer = new MessageFooter();
$footer->address_line_1 = "1311 E 4th St.";
$footer->city = "Tucson";
$footer->country = "US";
$footer->include_forward_email = true;
$footer->include_subscribe_link = true;
$footer->organization_name = "USA National Phenology Network";
$footer->postal_code = "85721";
$footer->state = "AZ";
$footer->forward_email_link_text = "Foward this email";
$footer->subscribe_link_text = "Subscribe Me!";

$campaign = new Campaign();
$campaign->email_content = getHTML();
$campaign->email_content_format = "XHTML";
$campaign->from_email = "erin@usanpn.org";
$campaign->from_name = "Nature's Notebook, USA National Phenology Network";
$campaign->greeting_salutations = "Dear";
$campaign->greeting_string = "Greetings!";
$campaign->greeting_name = "FIRST_NAME";
$campaign->is_permission_reminder_enabled = false;
$campaign->is_view_as_webpage_enabled = false;
$campaign->message_footer = $footer;
$campaign->name = $campaign_name;
$campaign->reply_to_email = "erin@usanpn.org";
$campaign->subject = "It's time to make your Nature's Notebook observations for the week!";
$campaign->template_type = "CUSTOM";
$campaign->text_content = getTextVersion();
$campaign->sent_to_contact_lists[] = $list;

/**
 * Then create the campaign on the remote server and if we're not in debug
 * mode, go ahead and schedule it as well.
 */
try{
    $cc->emailMarketingService->addCampaign($cc_access_token, $campaign);
    /*
     * We have to get the campaign from the service right after creating it
     * because otherwise the id field isn't initialized.
     */
    $saved_campaign = getCampaign($cc, $cc_access_token, $campaign_name);
    if(!$debug){

        $date = new DateTime();
        $date->add(new DateInterval('PT25M'));
        $schedule = new Schedule();
        $schedule->scheduled_date = $date->format('Y-m-d\TH:i:s');

        $cc->campaignScheduleService->addSchedule($cc_access_token, $saved_campaign->id, $schedule); 
    }

} catch (Exception $ex) {
    
    print_r($ex);
    die();
}




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



function getTextVersion(){
    return "<text>~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Your weekly reminder to observe
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Dear \$SUBSCRIBER.FIRSTNAME,
It's time to get outside and check on the plants and animals that you registered
 in Nature's Notebook!
During the seasons when your plants and animals are active, we recommend making 
observations 2-3 times per week. You can observe less frequently during the times
of year when your plants are dormant or animals are absent.
Remember that the \"no\" observations that you report when you do not see anything
 happening are just as important as the \"yes\" observations! It's especially important
to document those \"no\" observations right before you capture the first yes and after
you capture the last yes. These help the researchers and resource managers who use
your data to better pinpoint the start, duration, and end of a particular phenophase.
Thank you so much for being part of Nature's Notebook. We couldn't do it without
 you.
Happy Observing!
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Contact

Erin Posthumus
Outreach Coordinator
<a href=\"mailto:erin@usanpn.org\" track=\"on\" >erin@usanpn.org</a>
<a href=\"https://www.usanpn.org/about/staff#erin\" track=\"on\" >bio</a>
<a href=\"http://www.naturesnotebook.org\" track=\"on\" >www.naturesnotebook.org</a>
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

 Copyright © 2017. All Rights Reserved.</text>";
}


/**
 * This function just returns the HTML content of the email. This was very difficult
 * to create so here's a note on how it was done.
 * First Erin went in and manually made the campaign email as she saw fit using
 * the CC UI / editor. 
 * Next send that email as a 'test message' to myself. copy-paste the contents of
 * that message into this string.
 * Some massaging had to be done, mainly self-closing HTML tags needed to be made
 * explicitely so, because the service would not validate them.
 */
function getHTML(){
    return "<html><body><div align=\"center\">
 <table class=\"m_-3170420819794869607m_-1274026690516571946OuterBGColor\" style=\"background-color:#808080\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" bgcolor=\"#808080\">
 <tbody><tr>
 <td class=\"m_-3170420819794869607m_-1274026690516571946Body\" style=\"padding:24px 24px 24px 24px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" align=\"center\">

 <table class=\"m_-3170420819794869607m_-1274026690516571946TopMarginBGColor\" style=\"background:transparent\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>
 <td rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">
 <table class=\"m_-3170420819794869607m_-1274026690516571946TopMarginWidth\" style=\"width:600px\" border=\"0\" width=\"1\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946TopMargin\" style=\"padding:0px 0px 0px 0px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">

 <table id=\"m_-3170420819794869607m_-1274026690516571946content_LETTER.BLOCK1\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><tbody><tr><td style=\"color:#000000\" rowspan=\"1\" colspan=\"1\" valign=\"top\" align=\"center\"><img name=\"m_-3170420819794869607_m_-1274026690516571946_ACCOUNT.IMAGE.1106\" src=\"https://ci3.googleusercontent.com/proxy/ErL5r9ipt3rYD7xAvDtI-SN8-Baw4w-tqTKdYc6-7xTC5brNxHMSUKQB-WxIThMpY24FzoSq0BD9-gSfxw183TpVsrjiS_zN7Krmxqf072H2Aehm20SlAcEC9cD_hJK-s0uyN41w2CBHgoBK6MhEpg=s0-d-e1-ft#https://mlsvc01-prod.s3.amazonaws.com/a532cefb001/7c183962-8e5a-48f2-957d-f0db4622e477.png\" class=\"CToWUd\" border=\"0\" vspace=\"0\" width=\"600\" hspace=\"0\"/></td></tr></tbody></table>

 

 </td>

 </tr>
 </tbody></table>
 </td>
 </tr>
 </tbody></table>

 <table class=\"m_-3170420819794869607m_-1274026690516571946MainWidth\" style=\"width:600px\" border=\"0\" width=\"1\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>
 <td class=\"m_-3170420819794869607m_-1274026690516571946OuterBorderBGColor m_-3170420819794869607m_-1274026690516571946OuterBorder\" style=\"background-color:#f1f1e2;padding:1px 1px 1px 1px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" bgcolor=\"#F1F1E2\" align=\"center\">

 <table class=\"m_-3170420819794869607m_-1274026690516571946InnerBGColor\" style=\"background-color:#ffffff\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" bgcolor=\"#ffffff\">
 <tbody><tr>
 <td rowspan=\"1\" colspan=\"1\" valign=\"top\" align=\"center\">

 <table class=\"m_-3170420819794869607m_-1274026690516571946TopBGColor\" style=\"background:transparent\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946Top\" style=\"padding:0px 0px 0px 0px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">

 <table style=\"background-color:#459524\" id=\"m_-3170420819794869607m_-1274026690516571946content_LETTER.BLOCK3\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" bgcolor=\"#459524\"><tbody><tr><td style=\"color:#ffffff;text-align:left;font-size:14pt;font-family:Arial,Helvetica,sans-serif;padding:14px 16px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" align=\"left\">
<div>Your weekly reminder to observe</div>
</td></tr></tbody></table>

 

 <table border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>
 <td class=\"m_-3170420819794869607m_-1274026690516571946Spacer\" style=\"line-height:1px;height:1px;padding-bottom:9px\" rowspan=\"1\" colspan=\"1\" height=\"1\" align=\"center\"><img style=\"display:block\" alt=\"\" src=\"https://ci6.googleusercontent.com/proxy/zby7Wbp5IDoInk5bbCRY9WfGCyKxfn2FoANdjAn7r-Yz0x0mnxsS1LXSkDAE3cfnEaqVy_UhiRCZz66YfpbUugxNONoli0IeGOfvXpM=s0-d-e1-ft#http://img.constantcontact.com/letters/images/sys/S.gif\" class=\"CToWUd\" border=\"0\" vspace=\"0\" width=\"5\" hspace=\"0\" height=\"1\" /></td>
 </tr>
 </tbody></table><table style=\"display:table\" id=\"m_-3170420819794869607m_-1274026690516571946content_LETTER.BLOCK6\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><tbody><tr><td style=\"color:#000000;font-family:Arial,Helvetica,sans-serif;padding:5px 10px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" align=\"left\">
<div>
<div style=\"color:#4f4c4c;font-size:12pt\"><b>Dear \$SUBSCRIBER.FIRSTNAME,</b></div>
<img class=\"m_-3170420819794869607m_-1274026690516571946cc-image-resize CToWUd\" name=\"m_-3170420819794869607_m_-1274026690516571946_ACCOUNT.IMAGE.2144\" src=\"https://ci6.googleusercontent.com/proxy/AnTiCIcRY2c0ik3OSoJETD09rTGy8XTkHzAtdlm93NWRXCmGh9cZ9U9Vs1LpAK1xoXcOiKzKYdXKKNmeSgE2ktTtTsL9PJJ_ySrWeS8QwywdM_0709xZqKuDAfD_GF7BrmbQqYqKHvgm5-9Lg5y13A=s0-d-e1-ft#https://mlsvc01-prod.s3.amazonaws.com/a532cefb001/319dd19c-768e-4f87-9f30-03b54a4ef5c0.png\" border=\"0\" vspace=\"10\" width=\"173\" hspace=\"10\" height=\"82\" align=\"right\" /></div>
<div><br /><div style=\"color:#666666;font-size:12pt\"><span>It's time to get outside and check on the plants and animals that you registered&nbsp;</span><span style=\"font-size:12pt\">in&nbsp;</span><em style=\"font-size:12pt\">Nature's Notebook</em><span style=\"font-size:12pt\">!</span></div><div style=\"color:#666666;font-size:12pt\"><span><br /></span></div><div style=\"color:#666666;font-size:12pt\"><span>During the seasons when your plants and animals are active, we recommend making observations <strong>2-3 times per week</strong>. You can observe less frequently during the times of year when your plants are dormant or animals are absent.&nbsp;</span></div><div style=\"color:#666666;font-size:12pt\"><span><br /></span></div><div style=\"color:#666666;font-size:12pt\"><span>Remember that the <strong>\"no\" observations</strong> that you report when you do not see anything happening are just as important as the \"yes\" observations! It's especially important to document those \"no\" observations right before you capture the first ye</span><span style=\"font-size:12pt\">s and after you capture the last yes. These help the researchers and resource managers who use your data to better pinpoint the start, duration, and end of a particular phenophase.</span></div><div style=\"color:#666666;font-size:12pt\"><img class=\"m_-3170420819794869607m_-1274026690516571946cc-image-resize CToWUd\" name=\"m_-3170420819794869607_m_-1274026690516571946_ACCOUNT.IMAGE.2140\" src=\"https://ci3.googleusercontent.com/proxy/3wN1uRUr-cKmYc8S0QLTPi9kdjfYFrEmm4V8mCQN_6RORhrXe66-TOe_qKJMWzlOMq4LaobzVJNOZUw5qcpCcP7sdUZStOWaR0fcAJTZS1uuI7RhHYZuTfR4VqBBfs8P5URKSEDVlU8d5_lm8QDzhg=s0-d-e1-ft#https://mlsvc01-prod.s3.amazonaws.com/a532cefb001/1c7ac8de-e30f-4446-ae34-b0b17c5cbd8c.png\" border=\"0\" vspace=\"15\" width=\"90\" hspace=\"15\" height=\"115\" align=\"left\" /></div><div style=\"color:#666666;font-size:12pt\"><span><br /></span></div><div style=\"color:#666666;font-size:12pt\"><span><br /></span></div><div style=\"color:#666666;font-size:12pt\"><span>Thank you so much for being part of <em>Nature's Notebook. </em>We couldn't do it without you.</span></div><div style=\"color:#666666;font-size:12pt\"><span><br /></span></div><div style=\"color:#666666;font-size:12pt\"><span>Happy Observing!&nbsp;</span></div><div style=\"color:#666666;text-align:center\" align=\"center\"><span style=\"font-size:14.6667px\"> <img name=\"m_-3170420819794869607_m_-1274026690516571946_ACCOUNT.IMAGE.2143\" src=\"https://ci4.googleusercontent.com/proxy/KSHGY1-HHEA9bzZj7mkBlzbL5Wc1cx_IMVbfnUYsV2fN1WnZZu3wo3jzGGr4VOvzf2YpuW1z-NxuWxeMy1eYdZsLluEy3uOJWvkGtJWWPLBLcjoje6iBo8zq3Ld8-J8Z8VyulbUYLXHZNQCHHPv9wQ=s0-d-e1-ft#https://mlsvc01-prod.s3.amazonaws.com/a532cefb001/f46a4068-65fc-47ea-a024-c9905890d04d.jpg\" class=\"CToWUd a6T\" tabindex=\"0\" border=\"0\" vspace=\"5\" width=\"557\" hspace=\"5\" height=\"217\" /><div class=\"a6S\" dir=\"ltr\" style=\"opacity: 0.01; left: 919.5px; top: 963.5px;\"><div id=\":1q6\" class=\"T-I J-J5-Ji aQv T-I-ax7 L3 a5q\" role=\"button\" tabindex=\"0\" aria-label=\"Download attachment \" data-tooltip-class=\"a1V\" data-tooltip=\"Download\"><div class=\"aSK J-J5-Ji aYr\"></div></div></div> &nbsp;</span></div></div>
</td></tr></tbody></table></td>

 </tr>
 </tbody></table>

 <table class=\"m_-3170420819794869607m_-1274026690516571946TopFullColumnBGColor\" style=\"background:transparent\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946TopFullColumn\" style=\"padding:0px 10px 0px 10px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">

 

 </td>

 </tr>
 </tbody></table>

 <table class=\"m_-3170420819794869607m_-1274026690516571946MultiColumnSplitBGColor\" style=\"background:transparent\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946MainColumnBGColor\" style=\"background:transparent\" rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">
 <table border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946MainColumn\" style=\"padding:0px 10px 0px 10px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">

 

 

 

 <table border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>
 <td class=\"m_-3170420819794869607m_-1274026690516571946Spacer\" style=\"line-height:1px;height:1px;padding-bottom:9px\" rowspan=\"1\" colspan=\"1\" height=\"1\" align=\"center\"><img style=\"display:block\" alt=\"\" src=\"https://ci6.googleusercontent.com/proxy/zby7Wbp5IDoInk5bbCRY9WfGCyKxfn2FoANdjAn7r-Yz0x0mnxsS1LXSkDAE3cfnEaqVy_UhiRCZz66YfpbUugxNONoli0IeGOfvXpM=s0-d-e1-ft#http://img.constantcontact.com/letters/images/sys/S.gif\" class=\"CToWUd\" border=\"0\" vspace=\"0\" width=\"5\" hspace=\"0\" height=\"1\" /></td>
 </tr>
 </tbody></table>

 </td>

 </tr>
 </tbody></table>
 </td>

 <td class=\"m_-3170420819794869607m_-1274026690516571946SideColumnBGColor\" style=\"background:transparent\" rowspan=\"1\" colspan=\"1\" valign=\"top\" align=\"center\">
 <table border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946SideColumn\" style=\"padding:0px 10px 0px 10px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">
 </td>
 </tr>
 </tbody></table>
 <table class=\"m_-3170420819794869607m_-1274026690516571946SideColumnWidth\" style=\"width:210px\" border=\"0\" width=\"1\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>
 <td style=\"line-height:1px;height:1px\" rowspan=\"1\" colspan=\"1\" align=\"center\"><img style=\"display:block\" alt=\"\" src=\"https://ci6.googleusercontent.com/proxy/zby7Wbp5IDoInk5bbCRY9WfGCyKxfn2FoANdjAn7r-Yz0x0mnxsS1LXSkDAE3cfnEaqVy_UhiRCZz66YfpbUugxNONoli0IeGOfvXpM=s0-d-e1-ft#http://img.constantcontact.com/letters/images/sys/S.gif\" class=\"CToWUd\" border=\"0\" vspace=\"0\" width=\"5\" hspace=\"0\" height=\"1\" /></td>
 </tr>
 </tbody></table>
 </td>

 </tr>
 </tbody></table>

 <table class=\"m_-3170420819794869607m_-1274026690516571946BottomFullColumnBGColor\" style=\"background:transparent\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946BottomFullColumn\" style=\"padding:0px 12px 0px 12px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">


 </td>

 </tr>
 </tbody></table>

 <table class=\"m_-3170420819794869607m_-1274026690516571946BottomBGColor\" style=\"background:transparent\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946Bottom\" style=\"padding:0px 0px 0px 0px\" rowspan=\"1\" colspan=\"1\" valign=\"middle\" width=\"100%\" align=\"center\">

 

 <table style=\"background-color:#323232;display:table\" id=\"m_-3170420819794869607m_-1274026690516571946content_LETTER.BLOCK25\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><tbody><tr><td class=\"m_-3170420819794869607m_-1274026690516571946FooterText\" style=\"color:#ffffff;font-size:10pt;font-family:Arial,Helvetica,sans-serif;padding:14px 26px 20px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" align=\"left\">
<div style=\"font-size:16pt\">Contact<br /></div>
<table style=\"border-style:none;border-width:0px\" width=\"100%\" cellspacing=\"0\" cellpadding=\"3\" align=\"none\"><tbody><tr><td style=\"vertical-align:top;height:95px;color:#001a81;font-size:16px;font-style:normal;width:100px;font-family:Arial,Helvetica,sans-serif;font-weight:bold\" rowspan=\"1\" colspan=\"1\">&nbsp;      <img name=\"m_-3170420819794869607_m_-1274026690516571946_ACCOUNT.IMAGE.1210\" src=\"https://ci4.googleusercontent.com/proxy/mrrIWk80KYJ6uEWxeHEqMo0hxsttww1MLv1Jx8tVxGEhYwbGvJ2yEGdcAYQOBt0hfg-jMEK7uUqlGEaFRFKfHUX4Y7gfrtD4XaqS-eFPyAqBaGLV8SfYNDBOks_mh21jZmJihCg0aj3F8DKkDg5VKg=s0-d-e1-ft#https://mlsvc01-prod.s3.amazonaws.com/a532cefb001/be233bac-5862-4667-92e5-4a7d8d1a2485.jpg\" class=\"CToWUd\" border=\"0\" vspace=\"5\" width=\"64\" hspace=\"5\" height=\"76\" /></td><td style=\"vertical-align:top;height:95px;color:#ffffff;font-size:10px;font-style:normal;width:259px;font-family:Arial,Helvetica,sans-serif\" rowspan=\"1\" colspan=\"1\">
<div style=\"font-size:10pt\">Erin Posthumus</div>
<div style=\"font-size:10pt\">Outreach Coordinator</div>
<div style=\"color:#99b826;font-size:10pt\"><a style=\"color:rgb(153,184,38);text-decoration:none;font-weight:bold\" href=\"mailto:erin@usanpn.org\" shape=\"rect\" target=\"_blank\">erin@usanpn.org</a></div>
<div style=\"color:#99b826;font-size:10pt;text-decoration:underline\"><a style=\"color:rgb(153,184,38);text-decoration:underline;font-weight:bold\" href=\"http://r20.rs6.net/tn.jsp?t=xbypze9ab.0.0.99iy54cab.0&amp;id=preview&amp;r=3&amp;p=https%3A%2F%2Fwww.usanpn.org%2Fabout%2Fstaff%23erin\" shape=\"rect\" alt=\"https://www.usanpn.org/about/staff#erin\" target=\"_blank\" data-saferedirecturl=\"https://www.google.com/url?hl=en&amp;q=http://r20.rs6.net/tn.jsp?t%3Dxbypze9ab.0.0.99iy54cab.0%26id%3Dpreview%26r%3D3%26p%3Dhttps%253A%252F%252Fwww.usanpn.org%252Fabout%252Fstaff%2523erin&amp;source=gmail&amp;ust=1491927827065000&amp;usg=AFQjCNE1z1Cxxwy8hUN4RdwHtmH1CFMNNA\">bio</a></div>
<div style=\"color:#99b826;font-size:10pt;text-decoration:underline\"><a style=\"color:rgb(153,184,38);text-decoration:underline;font-weight:bold\" shape=\"rect\" href=\"http://r20.rs6.net/tn.jsp?t=xbypze9ab.0.0.99iy54cab.0&amp;id=preview&amp;r=3&amp;p=http%3A%2F%2Fwww.naturesnotebook.org\" alt=\"http://www.naturesnotebook.org\" target=\"_blank\" data-saferedirecturl=\"https://www.google.com/url?hl=en&amp;q=http://r20.rs6.net/tn.jsp?t%3Dxbypze9ab.0.0.99iy54cab.0%26id%3Dpreview%26r%3D3%26p%3Dhttp%253A%252F%252Fwww.naturesnotebook.org&amp;source=gmail&amp;ust=1491927827065000&amp;usg=AFQjCNGve5lC4HXK2oLfQL3y6vFyvdm_lA\">www.naturesnotebook.org</a></div>
</td><td style=\"vertical-align:bottom;color:#ffffff;width:165px\" rowspan=\"1\" colspan=\"1\"><img name=\"m_-3170420819794869607_m_-1274026690516571946_ACCOUNT.IMAGE.912\" src=\"https://ci4.googleusercontent.com/proxy/LahpzrWqvqyb9ymMDoMRsmlIB7QzTSzM11JhkM9sGI26XG380XKjCdw4KY-ilAjrgvEmqvnv4F0YwS9OI3fYHJIamrH7S2IrXVdBf1Dof5eyYKr-sMYrVhp0SexX1qLyyTt_cqVx2QBgqjxg_G1fEQ=s0-d-e1-ft#https://mlsvc01-prod.s3.amazonaws.com/a532cefb001/3bea8812-ad70-4ea9-932b-83c67c5a7266.gif\" class=\"CToWUd\" border=\"0\" vspace=\"5\" width=\"163\" hspace=\"5\" height=\"53\" align=\"right\" /><br /><br /></td></tr></tbody></table>
</td></tr></tbody></table>

 </td>

 </tr>
 </tbody></table>

 </td>
 </tr>
 </tbody></table>
 </td>
 </tr>
 </tbody></table>

 <table class=\"m_-3170420819794869607m_-1274026690516571946BottomMarginBGColor\" style=\"background:transparent\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>
 <td rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">
 <table class=\"m_-3170420819794869607m_-1274026690516571946BottomMarginWidth\" style=\"width:600px\" border=\"0\" width=\"1\" cellspacing=\"0\" cellpadding=\"0\">
 <tbody><tr>

 <td class=\"m_-3170420819794869607m_-1274026690516571946BottomMargin\" style=\"padding:0px 0px 0px 0px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" width=\"100%\" align=\"center\">

 <table id=\"m_-3170420819794869607m_-1274026690516571946content_LETTER.BLOCK26\" border=\"0\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><tbody><tr><td class=\"m_-3170420819794869607m_-1274026690516571946OuterText m_-3170420819794869607m_-1274026690516571946Copyright\" style=\"color:#000000;font-size:8pt;font-family:Arial,Helvetica,sans-serif;padding:0px 8px 0px 8px\" rowspan=\"1\" colspan=\"1\" valign=\"top\" align=\"center\">
<div><br /> Copyright © 2017. All Rights Reserved.<br /> <br /></div>
</td></tr></tbody></table>

 </td>

 </tr>
 </tbody></table>
 </td>
 </tr>
 </tbody></table>

 </td>
 </tr>
 </tbody></table>
</div>
</body></html>";
}