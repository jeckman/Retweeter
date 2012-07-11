<?php
/*
 ======================================================================
 Retweeter 1.1 - Creating Twitter Groups
 
 1.1.1 Update: Twitter's tweet IDs and user IDs have grown in length such
       that the existing database column for PostId (which is a combination
	   of user id and tweet id) needs to be lengthened. You can do this 
	   just by altering the PostId column to varchar(30) - so the only 
	   update here is in the bundled SQL scripts. 
 
 1.1 Update: Added use of Twitter's native retweet API - REQUIRES DATABASE
     CHANGE - see readme. Thanks to Cody Wilson of QC Co-Lab 
     (https://www.qccolab.com/)  for the patch. 
     Also updated to store the md5 hash in the database rather than rehashing
     (literally) each time. 
     Finally, corrected project url in readme and main php file. (Thanks Will
     Bradley). 
 
 1.0 Update: Added OAuth. You will need to register your application and get
     appropriate consumer secret and token, as well as an OAuth token and 
     secret for your specific user. 
     Also added rt and @ to original user
 
 0.9.5 Update: Fix for situations in which user's screen name may have different
       case than string entered in Retweeter. Patch provided by Will Bradley 
       <http://willbradley.name/>. 
 
 0.9.4 Update: Fix for tweets which when retweeted exceed 140 chars. 
       Thanks again to Daniel Lee for the feedback. 
 
 0.9.3 Update: Fix for bug with query for already tweeted tweets. Thanks again
       to Daniel Lee for troubleshooting. 
 
 0.9.2 Update: Fix for bug which was allowing duplicate posts in the database
       Duplicates weren't retweeted, but did swell db size. 
       Also made hashtag case-insensitive - match UserName and username equally
       Thanks to Daniel Lee ( http://yankeeincanada.typepad.com/ ) for 
       identifying these issues. 

 0.9.1 Update: Fix for HTTP Status 417 errors returned by Twitter API,
       better error checking on what is returned from Twitter, and better
       variable naming in final while loop. (2/10/09).
 
 Scans the tweets of those followed by a given username. 
 When it finds a hashtag (#username) in those tweets, adds them to 
  a database and retweets them, prefixed by the tweeters username. 
 This enables all who follow the account to recieve the tagged
  posts of all others who follow the account.  
 
 Assumes it is being run from a crontab entry. 
 
 Remember Twitter API limits - run every 2 minutes. 
 
 by John Eckman, http://johneckman.com/
  
 Latest version:
     http://www.openparenthesis.org/code/twitter-api/
	 
 ----------------------------------------------------------------------
 LICENSE

 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>
 ======================================================================
*/


// Set username - which is also the hashtag retweeter will look for
$username = 'username';

// Setup database connection
$dbserver = 'database_host';
$dbuser = 'database_username';
$dbpass = 'database_password';
$dbname = 'database_name';

// we'll need some OAuth stuff here
// register your retweeter at http://dev.twitter.com/apps/new
$consumer_key = 'consumer_key';  
$consumer_request = 'consumer_secret';
  
// then click on "my token" on the resulting page and get these (make sure 
// you are logged in AS THE USERNAME you intend to use, as these keys are 
// specific to the user:  
$retweeter_oauth_token = 'oauth_token';
$retweeter_oauth_secret = 'oauth_secret';

// To use the old format rather than the new retweet API, change this to true  
define('USE_OLD_FORMAT',false);   
  
// most users should not have to config beyond here
require_once('twitteroauth.php');
  
$db_handle = mysql_connect($dbserver,$dbuser,$dbpass) or die('Could not connect: ' . mysql_error());
mysql_select_db($dbname) or die('Could not select db');

// get the md5 hash from db or make it if it doesn't exist
$query = "SELECT meta_value from conf WHERE meta_key = 'hash'";
$result = mysql_query($query) or die('Could not run query on log table' . mysql_error());
  
if ($result && (mysql_num_rows($result) == 0)) { 
  $oauth_hash = md5($consumer_key.$consumer_request.$retweeter_oauth_token.$retweeter_oauth_secret);
  $query = "INSERT into conf (meta_value,meta_key) VALUES ('" .trim($oauth_hash) . "','hash');";	
  $my_result = mysql_query($query) or die('Could not update oauth hash' . mysql_error());
} else {
  $oauth_hash = mysql_result($result,0); 
  echo 'Got Oauth hash, it is ' . $oauth_hash . '<br />';
}
$connection = new TwitterOAuth(
                                 $consumer_key, 
                                 $consumer_request, 
                                 $retweeter_oauth_token, 
                                 $retweeter_oauth_secret
                                 );
  
  
// The twitter API address
$url = 'http://twitter.com/statuses/friends_timeline.xml';

$buffer = $connection->get($url);
  
// check for success or failure
if (empty($buffer)) { echo 'got no data'; } else {
	$responseCode = $connection->http_code;
}
			
// Log status here
$myResponseCode = mysql_real_escape_string($responseCode,$db_handle);
$query = "INSERT INTO log (Status) VALUES ('" . $myResponseCode . "')";
$result = mysql_query($query) or die('Could not run query on log table' . mysql_error());
			
if ($responseCode == 200)
{
	$xml = new SimpleXMLElement($buffer);

	foreach( $xml->status as $twTweetNode)
	{
		$strTweet = $twTweetNode->text; 
		$strPostId = $twTweetNode->user->id . $twTweetNode->id;
		$strUser = $twTweetNode->user->screen_name;
		$strPlainPostId = $twTweetNode->id;
		
		//echo $strPostId . " " . $strUser . " ";

		// Since we're using Friends_timeline, need to strip out the user			
		if (strtolower($strUser) != strtolower($username))		
		{			
			$insert = 0;
			$tweetQuery = "SELECT PostId from tweet WHERE PostId = '". $strPostId ."'"; 
			
			//echo $tweetQuery;  // echo the query

			$result = mysql_query($tweetQuery) or die('Couldnt run query on tweetid' . mysql_error());			

			if (($result) && (mysql_num_rows($result) == 0)) 
			{
				// echo "this is a new tweet";
				$insert = 1;
			}
			
			// set hashtag and tweet to lower for case-insensitive comparison
			$myHashtag = "#" . strtolower($username); 
			if ((strpos(strtolower($strTweet),$myHashtag) > -1) && $insert == 1) 
			{
				$myTweet = mysql_real_escape_string($strTweet,$db_handle);
 
				$myQuery = "INSERT into tweet (PostId, User, Tweet, PlainPostID) VALUES ('" .
					trim($strPostId) . "','" . trim($strUser) .
					"','" . trim($myTweet) ."','". trim($strPlainPostId)  ."');";		
	
				$result = mysql_query($myQuery) or die('Couldnt insert tweet' . mysql_error());

				//echo "inserting tweet";					
			}
		} // end if for != $username

	} // end for each status
} else {
	echo '<p>Getting tweets failed, with status code ' . $responseCode . '</p>';
	echo '<p>Entire response was: '. print_r($buffer,true) .'</p>';
}// end if Status Code 200
		
// Now we'll go and check the db for tweets which have not yet been retweeted

$myQuery = "SELECT PostId,User,Tweet,Tweeted,PlainPostID FROM tweet WHERE Tweeted is NULL"; 

$result = mysql_query($myQuery) or die('Could not select tweets not tweeted' . mysql_error()); 
echo "Results of Tweeted is NULL query: " . mysql_num_rows($result);

// date for tweeted
$mysqldate = date('Y-m-d H:i:s');

// look at each un-retweeted tweet, post it, and set Tweeted date		
while($row = mysql_fetch_array($result))
{
  if(($row['PlainPostID'] == '') || (USE_OLD_FORMAT)) { 
    $myTweetUser = $row['User'];
    $myTweetText = $row['Tweet'];
    if((strlen($myTweetText) + strlen($myTweetUser) + strlen("rt: @ ")) > 138) {
      // Houston, we have a problem - this will be too big when retweeted
      $myTweetArray =  explode("\n", wordwrap($myTweetText,132-strlen($myTweetUser) ) );
      $myTweetText = $myTweetArray[0]  . " ..." ;	
    }
    $myTweet = "rt: @" . $myTweetUser . " " . $myTweetText; 
    $tweet_post_url = 'http://twitter.com/statuses/update.xml';	
    $buffer = $connection->post($tweet_post_url,array(
                                                    'status' => $myTweet,
                                                    'source' => 'retweeter'
                                                    ));
  } else { // tweet has a plain id, can use retweet api
    $myTweet = $myTweet = $row['PlainPostID']; 
    $tweet_post_url = 'http://api.twitter.com/1/statuses/retweet/' . $myTweet . '.xml';	
    $buffer = $connection->post($tweet_post_url);
  }
  // If it fails, don't mark it Tweeted, we'll get it next time
  if (empty($buffer)) { 
    echo 'got no data'; 
    $responseCode = '';
  } else {
    $responseCode = $connection->http_code;
  }
  if ($responseCode == 200) {
    echo 're-tweeted one';
		$myQuery = "UPDATE tweet SET Tweeted = '" . $mysqldate . "' WHERE PostId = '" . $row['PostId'] . "'";   
		$my_retweet_result = mysql_query($myQuery) or die('Could not update tweeted date' . mysql_error());
  } else {
    echo '<p>Re-tweet failed, with status code ' . $responseCode . '</p>';
	echo '<p>Entire response was: '. print_r($buffer,true) .'</p>';
  }
}
?>
