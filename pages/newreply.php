<?php
//  AcmlmBoard XD - Reply submission/preview page
//  Access: users

$title = __("New reply");

if(!$loguserid) //Not logged in?
	Kill(__("You must be logged in to post."));

if(isset($_POST['id']))
	$_GET['id'] = $_POST['id'];

if(!isset($_GET['id']))
	Kill(__("Thread ID unspecified."));

$tid = (int)$_GET['id'];
AssertForbidden("viewThread", $tid);
AssertForbidden("makeReply", $tid);

if($loguser['powerlevel'] < 0)
	Kill(__("You're banned. You can't post."));

$rThread = Query("select * from {threads} where id={0}", $tid);
if(NumRows($rThread))
{
	$thread = Fetch($rThread);
	$fid = $thread['forum'];
}
else
	Kill(__("Unknown thread ID."));

$rFora = Query("select * from {forums} where id={0}", $fid);
if(NumRows($rFora))
	$forum = Fetch($rFora);
else
	Kill("Unknown forum ID.");
$fid = $forum['id'];
AssertForbidden("viewForum", $fid);

$isHidden = (int)($forum['minpower'] > 0);

if($forum['minpowerreply'] > $loguser['powerlevel'])
	Kill(__("Your power is not enough."));

if($thread['closed'] && $loguser['powerlevel'] < 3)
	Kill(__("This thread is locked."));

$OnlineUsersFid = $fid;

write(
"
	<script type=\"text/javascript\">
			window.addEventListener(\"load\",  hookUpControls, false);
	</script>
");

MakeCrumbs(array($forum['title']=>actionLink("forum", $fid), actionLink("thread", $tid) => ParseThreadTags($thread['title']), __("New reply")=>""), $links);

if(!$thread['sticky'] && Settings::get("oldThreadThreshold") > 0 && $thread['lastpostdate'] < time() - (2592000 * Settings::get("oldThreadThreshold")))
	Alert(__("You are about to bump an old thread. This is usually a very bad idea. Please think about what you are about to do before you press the Post button."));


if(isset($_POST['actionpreview']))
{
	$previewPost['layoutblocked'] = $loguser['globalblock'];
	
	$previewPost['text'] = $_POST["text"];
	$previewPost['num'] = $loguser['posts']+1;
	$previewPost['posts'] = $loguser['posts']+1;
	$previewPost['id'] = "???";
	$previewPost['uid'] = $loguserid;
	$copies = explode(",","title,name,displayname,picture,sex,powerlevel,avatar,postheader,signature,signsep,regdate,lastactivity,lastposttime,rankset");
	foreach($copies as $toCopy)
		$previewPost[$toCopy] = $loguser[$toCopy];
	$previewPost['mood'] = (int)$_POST['mood'];
	$previewPost['options'] = 0;
	if($_POST['nopl']) $previewPost['options'] |= 1;
	if($_POST['nosm']) $previewPost['options'] |= 2;
	if($_POST['nobr']) $previewPost['options'] |= 4;
	MakePost($previewPost, POST_SAMPLE, array('forcepostnum'=>1, 'metatext'=>__("Preview")));
}
else if(isset($_POST['actionpost']))
{
	//Now check if the post is acceptable.
	$rejected = false;
	
	if(!$_POST['text'])
	{
		Alert(__("Enter a message and try again."), __("Your post is empty."));
		$rejected = true;
	}
	else if($thread['lastposter']==$loguserid && $thread['lastpostdate']>=time()-86400 && $loguser['powerlevel']<3)
	{
		Alert(__("You can't double post until it's been at least one day."), __("Sorry"));
		$rejected = true;
	}
	else
	{
		$lastPost = time() - $loguser['lastposttime'];
		if($lastPost < Settings::get("floodProtectionInterval"))
		{
			//Check for last post the user posted.
			$lastPost = Fetch(Query("SELECT * FROM {posts} WHERE user={0} ORDER BY date DESC LIMIT 1", $loguserid));

			//If it looks similar to this one, assume the user has double-clicked the button.
			if($lastPost["thread"] == $tid)
			{
				$pid = $lastPost["id"];
				die(header("Location: ".actionLink("thread", 0, "pid=".$pid."#".$pid)));
			}

			$rejected = true;
			Alert(__("You're going too damn fast! Slow down a little."), __("Hold your horses."));
		}
	}
	
	if(!$rejected)
	{
		$ninja = FetchResult("select id from {posts} where thread={0} order by date desc limit 0, 1", $tid);
		if(isset($_POST['ninja']) && $_POST['ninja'] != $ninja)
		{
			Alert(__("You got ninja'd. You might want to review the post made while you were typing before you submit yours."));
			$rejected = true;
		}
	}

	//TODO: Call a plugin bucket for plugins to be able to reject threads/posts too!

	if(!$rejected)
	{
		$post = $_POST['text'];

		$options = 0;
		if($_POST['nopl']) $options |= 1;
		if($_POST['nosm']) $options |= 2;
		if($_POST['nobr']) $options |= 4;

		if(CanMod($loguserid, $forum['id']))
		{
			if($_POST['lock'])
				$mod.= ", closed = 1";
			else if($_POST['unlock'])
				$mod.= ", closed = 0";
			if($_POST['stick'])
				$mod.= ", sticky = 1";
			else if($_POST['unstick'])
				$mod.= ", sticky = 0";
		}



		$rUsers = Query("update {users} set posts=posts+1, lastposttime={0} where id={1} limit 1", 
			time(), $loguserid);

		$rPosts = Query("insert into {posts} (thread, user, date, ip, num, options, mood) values ({0},{1},{2},{3},{4}, {5}, {6})", 
			$tid, $loguserid, time(), $_SERVER['REMOTE_ADDR'], ($loguser['posts']+1), $options, (int)$_POST['mood']);
		
		$pid = InsertId();

		$rPostsText = Query("insert into {posts_text} (pid,text) values ({0},{1})", $pid, $post);


		$rFora = Query("update {forums} set numposts=numposts+1, lastpostdate={0}, lastpostuser={1}, lastpostid={2} where id={3} limit 1", 
			time(), $loguserid, $pid, $fid);

		$rThreads = Query("update {threads} set lastposter={0}, lastpostdate={1}, replies=replies+1, lastpostid={2}".$mod." where id={3} limit 1",
			$loguserid, time(), $pid, $tid);

		Report("New reply by [b]".$loguser['name']."[/] in [b]".$thread['title']."[/] (".$forum['title'].") -> [g]#HERE#?pid=".$pid, $isHidden);

		$bucket = "newreply"; include("lib/pluginloader.php");

		die(header("Location: ".actionLink("thread", 0, "pid=".$pid."#".$pid)));
	}
}

$prefill = htmlspecialchars($_POST['text']);

if($_GET['link'])
{
	$prefill = ">>".(int)$_GET['link']."\r\n\r\n";
}
else if($_GET['quote'])
{
	$rQuote = Query("	select 
					p.id, p.deleted, pt.text,
					f.minpower,
					u.name poster
				from {posts} p
					left join {posts_text} pt on pt.pid = p.id and pt.revision = p.currentrevision 
					left join {threads} t on t.id=p.thread
					left join {forums} f on f.id=t.forum
					left join {users} u on u.id=p.user
				where p.id={0}", (int)$_GET['quote']);
	
	if(NumRows($rQuote))
	{
		$quote = Fetch($rQuote);

		//SPY CHECK!
		//Do we need to translate this line? It's not even displayed in its true form ._.
		if($quote['minpower'] > $loguser['powerlevel'])
			$quote['text'] = str_rot13("Pools closed due to not enough power. Prosecutors will be violated.");
			
		if ($quote['deleted'])
			$quote['text'] = __("Post is deleted");

		$prefill = "[quote=\"".htmlspecialchars($quote['poster'])."\" id=\"".$quote['id']."\"]".htmlspecialchars($quote['text'])."[/quote]";
		$prefill = str_replace("/me", "[b]* ".htmlspecialchars($quote['poster'])."[/b]", $prefill);
	}
}

if($_POST['nopl'])
	$nopl = "checked=\"checked\"";
if($_POST['nosm'])
	$nosm = "checked=\"checked\"";
if($_POST['nobr'])
	$nobr = "checked=\"checked\"";

if($_POST['mood'])
	$moodSelects[(int)$_POST['mood']] = "selected=\"selected\" ";
$moodOptions = "<option ".$moodSelects[0]."value=\"0\">".__("[Default avatar]")."</option>\n";

$rMoods = Query("select mid, name from {moodavatars} where uid={0} order by mid asc", $loguserid);

while($mood = Fetch($rMoods))
	$moodOptions .= format(
"
	<option {0} value=\"{1}\">{2}</option>
",	$moodSelects[$mood['mid']], $mood['mid'], htmlspecialchars($mood['name']));

$ninja = FetchResult("select id from {posts} where thread={0} order by date desc limit 0, 1", $tid);

if(CanMod($loguserid, $fid))
{
	$mod = "\n\n<!-- Mod options -->\n";
	if(!$thread['closed'])
		$mod .= "<label><input type=\"checkbox\" name=\"lock\">&nbsp;".__("Close thread", 1)."</label>\n";
	else
		$mod .= "<label><input type=\"checkbox\" name=\"unlock\">&nbsp;".__("Open thread", 1)."</label>\n";

	if(!$thread['sticky'])
		$mod .= "<label><input type=\"checkbox\" name=\"stick\">&nbsp;".__("Sticky", 1)."</label>\n";
	else
		$mod .= "<label><input type=\"checkbox\" name=\"unstick\">&nbsp;".__("Unstick", 1)."</label>\n";

	$mod .= "\n\n";
}

print "
	<table style=\"width: 100%;\">
		<tr>
			<td style=\"vertical-align: top; border: none;\">
				<form action=\"".actionLink("newreply")."\" method=\"post\">
					<input type=\"hidden\" name=\"ninja\" value=\"$ninja\" />
					<table class=\"outline margin width100\">
						<tr class=\"header1\">
							<th colspan=\"2\">
								".__("New reply")."
							</th>
						</tr>
						<tr class=\"cell0\">
							<td>
								<label for=\"text\">
									".__("Post")."
								</label>
							</td>
							<td>
								<textarea id=\"text\" name=\"text\" rows=\"16\" style=\"width: 98%;\">$prefill</textarea>
							</td>
						</tr>
						<tr class=\"cell2\">
							<td></td>
							<td>
								<input type=\"submit\" name=\"actionpost\" value=\"".__("Post")."\" /> 
								<input type=\"submit\" name=\"actionpreview\" value=\"".__("Preview")."\" />
								<select size=\"1\" name=\"mood\">
									$moodOptions
								</select>
								<label>
									<input type=\"checkbox\" name=\"nopl\" $nopl />&nbsp;".__("Disable post layout", 1)."
								</label>
								<label>
									<input type=\"checkbox\" name=\"nosm\" $nosm />&nbsp;".__("Disable smilies", 1)."
								</label>
								<label>
									<input type=\"checkbox\" name=\"nobr\" $nobr />&nbsp;".__("Disable auto-<br>", 1)."
								</label>
								<input type=\"hidden\" name=\"id\" value=\"$tid\" />
								$mod
							</td>
						</tr>
					</table>
				</form>
			</td>
			<td style=\"width: 20%; vertical-align: top; border: none;\">";
			
DoSmileyBar();
DoPostHelp();

write("
			</td>
		</tr>
	</table>
");

$rPosts = Query("select 
{posts}.id, {posts}.date, {posts}.num, {posts}.deleted, {posts}.options, {posts}.mood, {posts}.ip, {posts_text}.text, {posts_text}.text, {posts_text}.revision, {users}.id as uid, {users}.name, {users}.displayname, {users}.rankset, {users}.powerlevel, {users}.sex, {users}.posts
from {posts} left join {posts_text} on {posts_text}.pid = {posts}.id and {posts_text}.revision = {posts}.currentrevision left join {users} on {users}.id = {posts}.user
where thread={0} and deleted=0 order by date desc limit 0, 20", $tid);
if(NumRows($rPosts))
{
	$posts = "";
	while($post = Fetch($rPosts))
	{
		$cellClass = ($cellClass+1) % 2;

		$poster = $post;
		$poster['id'] = $post['uid'];

		$nosm = $post['options'] & 2;
		$nobr = $post['options'] & 4;

		$posts .= Format(
"
		<tr>
			<td class=\"cell2\" style=\"width: 15%; vertical-align: top;\">
				{1}
			</td>
			<td class=\"cell{0}\">
				<button style=\"float: right;\" onclick=\"insertQuote({2});\">".__("Quote")."</button>
				<button style=\"float: right;\" onclick=\"insertChanLink({2});\">".__("Link")."</button>
				{3}
			</td>
		</tr>
",	$cellClass, UserLink($poster), $post['id'], CleanUpPost($post['text'], $poster['name'], $nosm, $nobr));
	}
	Write(
"
	<table class=\"outline margin\">
		<tr class=\"header0\">
			<th colspan=\"2\">".__("Thread review")."</th>
		</tr>
		{0}
	</table>
",	$posts);
}
?>
