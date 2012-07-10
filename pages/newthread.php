<?php
//  AcmlmBoard XD - Thread submission/preview page
//  Access: users

$title = __("New thread");

AssertForbidden("makeThread");

if(!$loguserid) //Not logged in?
	Kill(__("You must be logged in to post."));

if(isset($_POST['id']))
	$_GET['id'] = $_POST['id'];

if(!isset($_GET['id']))
	Kill(__("Forum ID unspecified."));

$fid = (int)$_GET['id'];

if($loguser['powerlevel'] < 0)
	Kill(__("You're banned."));

$rFora = Query("select * from {forums} where id={0}", $fid);
if(NumRows($rFora))
	$forum = Fetch($rFora);
else
	Kill(__("Unknown forum ID."));

if($forum['locked'])
	Kill(__("This forum is locked."));

if($forum['minpowerthread'] > $loguser['powerlevel'])
	Kill(__("You are not allowed to post threads in this forum."));

if(!isset($_POST['poll']) || isset($_GET['poll']))
	$_POST['poll'] = $_GET['poll'];


$OnlineUsersFid = $fid;

MakeCrumbs(array($forum['title']=>actionLink("forum", $fid), __("New thread")=>""), $links);

if(isset($_POST['actionpreview']))
{
	if($_POST['poll'])
	{
		$options = array();
		$noColors = 0;
		$defaultColors = array(
			"#000000","#0000B6","#00B600","#00B6B6","#B60000","#B600B6","#B66700","#B6B6B6",
			"#676767","#6767FF","#67FF67","#67FFFF","#FF6767","#FF67FF","#FFFF67","#FFFFFF",);
		for($i = 0; $i < $_POST['pollOptions']; $i++)
		{
			$options[] = array("choice"=>$_POST['pollOption'.$i], "color"=>$_POST['pollColor'.$i]);
		}
		$totalVotes = count($options);
		foreach($options as $option)
		{
			if($option['color'] == "")
				$option['color'] = $defaultColors[($pops + 9) % 16];
			
			$votes = 1;

			$cellClass = ($cellClass+1) % 2;
			$label = format("{1}", $pc[$pops], $option['choice']);

			$bar = "";
			if($totalVotes > 0)
			{
				$width = 100 * ($votes / $totalVotes);
				$alt = format("{0}&nbsp;of&nbsp;{1},&nbsp;{2}%", $votes, $totalVotes, $width);
				$bar = format("<div class=\"pollbar\" style=\"background: {0}; width: {1}%;\" title=\"{2}\">&nbsp;{3}</div>", $option['color'], $width, $alt, $votes);
				if($width == 0)
					$bar = "&nbsp;".$votes;
			}			

			$pollLines .= format(
"
	<tr class=\"cell{0}\">
		<td>
			{1}
		</td>
		<td class=\"width75\">
			<div class=\"pollbarContainer\">
				{2}
			</div>
		</td>
	</tr>
", $cellClass, $label, $bar);
			$pops++;
		}
		write(
"
	<table class=\"outline margin\">
		<tr class=\"header0\">
			<th colspan=\"2\">
				".__("Poll")."
			</th>
		</tr>
		<tr class=\"cell0\">
			<td colspan=\"2\">
				{1}
			</td>
		</tr>
		{2}
	</table>
", $cellClass, $_POST['pollQuestion'], $pollLines);
	}

	$previewPost['text'] = $_POST["text"];
	$previewPost['num'] = $loguser['posts']+1;
	$previewPost['posts'] = $loguser['posts']+1;
	$previewPost['id'] = "???";
	$previewPost['options'] = 0;
	if($_POST['nopl']) $previewPost['options'] |= 1;
	if($_POST['nosm']) $previewPost['options'] |= 2;
	if($_POST['nobr']) $previewPost['options'] |= 4;
	$previewPost['mood'] = (int)$_POST['mood'];
	$previewPost['uid'] = $loguserid;
	$copies = explode(",","title,name,displayname,picture,sex,powerlevel,avatar,postheader,signature,signsep,regdate,lastactivity,lastposttime");
	foreach($copies as $toCopy)
		$previewPost[$toCopy] = $loguser[$toCopy];
	$previewPost['layoutblocked'] = $loguser['globalblock'];
	MakePost($previewPost, POST_SAMPLE, array('forcepostnum'=>1, 'metatext'=>__("Preview")));
}
else if(isset($_POST['actionpost']))
{
	$titletags = parseThreadTags($_POST['title']);
	$trimmedTitle = trim(str_replace('&nbsp;', ' ', $titletags[0]));

	//Now check if the thread is acceptable.
	$rejected = false;
	
	if(!$_POST['text'])
	{
		Alert(__("Enter a message and try again."), __("Your post is empty."));
		$rejected = true;
	}
	else if(!$trimmedTitle)
	{
		Alert(__("Enter a thread title and try again."), __("Your thread is unnamed."));
		$rejected = true;
	}
	else if($_POST['poll'])
	{
		$optionCount = 0;
		for($pops = 0; $pops < $_POST['pollOptions']; $pops++)
			if($_POST['pollOption'.$pops])
				$optionCount++;
				
		if($optionCount < 2)
		{
			Alert(__("You need to enter at least two options to make a poll."), __("Invalid poll."));
			$rejected = true;
		}
		
		if(!$rejected && !$_POST["pollQuestion"])
		{
			Alert(__("You need to enter a poll question to make a poll."), __("Invalid poll."));
			$rejected = true;
		}
	}
	else
	{
		$lastPost = time() - $loguser['lastposttime'];
		if($lastPost < Settings::get("floodProtectionInterval"))
		{
			//Check for last thread the user posted.
			$lastThread = Fetch(Query("SELECT * FROM {$dbpref}threads WHERE user=$loguserid ORDER BY id DESC LIMIT 1"));

			//If it looks similar to this one, assume the user has double-clicked the button.
			if($lastThread["forum"] == $fid && $lastThread["title"] == $_POST["title"])
				die(header("Location: ".actionLink("thread", $lastThread["id"])));

			$rejected = true;
			Alert(__("You're going too damn fast! Slow down a little."), __("Hold your horses."));
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

		if($_POST['iconid'])
		{
			$_POST['iconid'] = (int)$_POST['iconid'];
			if($_POST['iconid'] < 255)
				$iconurl = "img/icons/icon".$_POST['iconid'].".png";
			else
				$iconurl = $_POST['iconurl'];
		}
		else $iconurl = '';

		$mod = '0, 0';
		if(CanMod($loguserid, $forum['id']))
			$mod = (($_POST['lock'] == 'on') ? '1':'0').', '.(($_POST['stick'] == 'on') ? '1':'0');
		
		if($_POST['poll'])
		{
			$doubleVote = ($_POST['multivote']) ? 1 : 0;
			$rPoll = Query("insert into {poll} (question, doublevote) values ({0}, {1})", $_POST['pollQuestion'], $doubleVote);
			$pod = InsertId();
			for($pops = 0; $pops < $_POST['pollOptions']; $pops++)
			{
				if($_POST['pollOption'.$pops])
				{
					$pollColor = filterPollColors($_POST['pollColor'.$pops]);
					$rPollOption = Query("insert into {poll_choices} (poll, choice, color) values ({0}, {1}, {2})", $pod, $_POST['pollOption'.$pops], $pollColor);
				}
			}
		}
		else
			$pod = 0;

		$rThreads = Query("insert into {threads} (forum, user, title, icon, lastpostdate, lastposter, closed, sticky, poll) 
										  values ({0},   {1},  {2},   {3},  {4},          {5},        {2},   {6},     {7})", 
										    $fid, $loguserid, $_POST['title'], $iconurl, time(), $mod, $pod);
		$tid = InsertId();

		$rUsers = Query("update {users} set posts={0}, lastposttime={1} where id={2} limit 1", ($loguser['posts']+1), time(), $loguserid);

		$rPosts = Query("insert into {posts} (thread, user, date, ip, num, options, mood) 
									  values ({0},{1},{2},{3},{4}, {5}, {6})", $tid, $loguserid, time(), $_SERVER['REMOTE_ADDR'], ($loguser['posts']+1), $options, (int)$_POST['mood']);
		$pid = InsertId();

		$rPostsText = Query("insert into {posts_text} (pid,text) values ({0},{1})", $pid, $post);

		$rFora = Query("update {forums} set numthreads=numthreads+1, numposts=numposts+1, lastpostdate={0}, lastpostuser={1}, lastpostid={2} where id={3} limit 1", time(), $loguserid, $pid, $fid);
		
		Query("update {threads} set lastpostid = {0} where id = {1}", $pid, $tid);
		
		$isHidden = (int)($forum['minpower'] > 0);
		Report("New ".($_POST['poll'] ? "poll" : "thread")." by [b]".$loguser['name']."[/]: [b]".$_POST['title']."[/] (".$forum['title'].") -> [g]#HERE#?tid=".$tid, $isHidden);

		//newthread bucket
		$postingAsUser = $loguser;
		$thread["title"] = $_POST['title'];
		$thread["id"] = $tid;
		$bucket = "newthread"; include("lib/pluginloader.php");

		die(header("Location: ".actionLink("thread", $tid)));
	}
}

// Let the user try again.
$prefill = htmlspecialchars($_POST['text']);
$trefill = htmlspecialchars($_POST['title']);

if(!isset($_POST['iconid']))
	$_POST['iconid'] = 0;

if($_POST['nopl'])
	$nopl = "checked=\"checked\"";
if($_POST['nosm'])
	$nosm = "checked=\"checked\"";
if($_POST['nobr'])
	$nobr = "checked=\"checked\"";

$iconNoneChecked = ($_POST['iconid'] == 0) ? "checked=\"checked\"" : "";
$iconCustomChecked = ($_POST['iconid'] == 255) ? "checked=\"checked\"" : "";

$i = 1;
$icons = "";
while(is_file("img/icons/icon".$i.".png"))
{
	$checked = ($_POST['iconid'] == $i) ? "checked=\"checked\" " : "";
	$icons .= format(
"
							<label>
								<input type=\"radio\" {0} name=\"iconid\" value=\"{1}\" />
								<img src=\"img/icons/icon{1}.png\" alt=\"Icon {1}\" onclick=\"javascript:void()\" />
							</label>
", $checked, $i);
	$i++;
}

if($_POST["addpoll"])
	$_POST["poll"] = 1;
	
if($_POST["deletepoll"])
	$_POST["poll"] = 0;

if($_POST['poll'])
{
	$first = true;
	$pollOptions = "";
	for($pops = 0; $pops < $_POST['pollOptions']; $pops++)
	{
		$cellClass = ($cellClass+1) % 2;
		$fixed = htmlspecialchars($_POST['pollOption'.$pops]);
		$pollOptions .= format(
"
						<tr class=\"cell{0}\">
							<td>
								<label for=\"p{1}\">".__("Option {2}")."</label>
							</td>
							<td>
								<input type=\"text\" id=\"p{1}\" name=\"pollOption{1}\" value=\"{3}\" style=\"width: 50%;\" maxlength=\"40\" >&nbsp;
								<label>
									".__("Color", 1)."&nbsp;
									<input type=\"text\" name=\"pollColor{1}\" value=\"{4}\" size=\"10\" maxlength=\"7\" class=\"color {hash:true,required:false,pickerFaceColor:'black',pickerFace:3,pickerBorder:0,pickerInsetColor:'black',pickerPosition:'left',pickerMode:'HVS'}\" />
								</label>
								{5}
							</td>
						</tr>
",	$cellClass, $pops, $pops + 1, $fixed,
	filterPollColors($_POST['pollColor'.$pops]), ($first ? "&nbsp;(#rrggbb)" : ""));
		$first = false;
	}

	$multivote = "<label><input type=\"checkbox\" ".($_POST['multivote'] ? "checked=\"checked\"" : "")." name=\"multivote\" />&nbsp;".__("Multivote", 1)."</label>";

	$pollSettings = "
		<tr class=\"cell0\">
			<td>
				<label for=\"pq\">
					".__("Poll question")."
				</label>
			</td>
			<td>
				<input type=\"text\" id=\"pq\" name=\"pollQuestion\" value=\"".htmlspecialchars($_POST['pollQuestion'])."\" style=\"width: 98%;\" maxlength=\"100\" />
			</td>
		</tr>
		<tr class=\"cell1\">
			<td>
				<label for=\"pn\">
					".__("Number of options")."
				</label>
			</td>
			<td>
				<input type=\"text\" id=\"pn\" name=\"pollOptions\" value=\"".htmlspecialchars($_POST['pollOptions'])."\" size=\"2\" maxlength=\"2\" />
				<input type=\"submit\" name=\"actionsetpoll\" value=\"".__("Set")."\" />
			</td>
		</tr>
		<tr class=\"cell0\">
			<td>
			</td>
			<td>
				$multivote
			</td>
		</tr>
		$pollOptions";
	$pollSettings .= "<tr class=\"cell1\"><td></td><td><input type=\"submit\" name=\"deletepoll\" value=\"".__("Delete poll")."\" /></td></tr>";

}
else
	$pollSettings = "<tr class=\"cell1\"><td></td><td><input type=\"submit\" name=\"addpoll\" value=\"".__("Add poll")."\" /></td></tr>";

$pollSettings = "
	<tr class=\"cell0\"><td colspan=\"2\"></td></tr>
	$pollSettings
	<tr class=\"cell0\"><td  colspan=\"2\"></td></tr>";

print "
	<script src=\"".resourceLink("js/threadtagging.js")."\"></script>
	<table style=\"width: 100%;\">
		<tr>
			<td style=\"vertical-align: top; border: none;\">
				<form action=\"".actionLink("newthread")."\" method=\"post\">
					<table class=\"outline margin width100\">
						<tr class=\"header1\">
							<th colspan=\"2\">
								".__("New thread")."
							</th>
						</tr>
						<tr class=\"cell0\">
							<td>
								<label for=\"tit\">
									".__("Title")."
								</label>
							</td>
							<td id=\"threadTitleContainer\">
								<input type=\"text\" id=\"tit\" name=\"title\" style=\"width: 98%;\" maxlength=\"60\" value=\"$trefill\" />
							</td>
						</tr>
						<tr class=\"cell1\">
							<td>
								".__("Icon")."
							</td>
							<td class=\"threadIcons\">
								<label>
									<input type=\"radio\" $iconNoneChecked name=\"iconid\" value=\"0\" /> 
									<span>".__("None")."</span>
								</label> 
								$icons
								<br />
								<label>
									<input type=\"radio\" $iconCustomChecked name=\"iconid\" value=\"255\" /> 
									<span>".__("Custom")."</span>
								</label> 
								<input type=\"text\" id=\"iconurl\" name=\"iconurl\" style=\"width: 50%;\" maxlength=\"100\" value=\"".htmlspecialchars($_POST['iconurl'])."\" />
							</td>
						</tr>";

print $pollSettings;

if($_POST['mood'])
	$moodSelects[(int)$_POST['mood']] = "selected=\"selected\" ";
$moodOptions = "<option ".$moodSelects[0]."value=\"0\">".__("[Default avatar]")."</option>\n";
$rMoods = Query("select mid, name from {moodavatars} where uid={0} order by mid asc", $loguserid);
while($mood = Fetch($rMoods))
	$moodOptions .= format(
"
	<option {0} value=\"{1}\">{2}</option>
",	$moodSelects[$mood['mid']], $mood['mid'], htmlspecialchars($mood['name']));

if(CanMod($loguserid, $forum['id']))
{
	$mod = "\n\n<!-- Mod options -->\n";
	$mod .= "<label><input type=\"checkbox\" name=\"lock\">&nbsp;".__("Close thread", 1)."</label>\n";
	$mod .= "<label><input type=\"checkbox\" name=\"stick\">&nbsp;".__("Sticky", 1)."</label>\n";
	$mod .= "<!-- More could follow -->\n\n";
}

if(!$_POST['poll'] || $_POST['pollOptions'])
	$postButton = "<input type=\"submit\" name=\"actionpost\" value=\"".__("Post")."\" /> ";

write(
"
						<tr class=\"cell0\">
							<td>
								<label for=\"post\">
									Post
								</label>
							</td>
							<td>
								<textarea id=\"text\" name=\"text\" rows=\"16\" style=\"width: 98%;\">{0}</textarea>
							</td>
						</tr>
						<tr class=\"cell2\">
							<td></td>
							<td>
								{1}
								<input type=\"submit\" name=\"actionpreview\" value=\"".__("Preview")."\" />
								<select size=\"1\" name=\"mood\">
									{2}
								</select>
								<label>
									<input type=\"checkbox\" name=\"nopl\" {3} />&nbsp;".__("Disable post layout", 1)."
								</label>
								<label>
									<input type=\"checkbox\" name=\"nosm\" {4} />&nbsp;".__("Disable smilies", 1)."
								</label>
								<label>
									<input type=\"checkbox\" name=\"nobr\" {8} />&nbsp;".__("Disable auto-<br>", 1)."
								</label>
								<input type=\"hidden\" name=\"id\" value=\"{5}\" />
								<input type=\"hidden\" name=\"poll\" value=\"{6}\" />
								{7}
								{9}
							</td>
						</tr>
					</table>
				</form>
			</td>
			<td style=\"width: 200px; vertical-align: top; border: none;\">
",	$prefill, $postButton, $moodOptions, $nopl, $nosm, $fid, htmlspecialchars($_POST['poll']), "", $nobr, $mod);

DoSmileyBar();
DoPostHelp();

write("
			</td>
		</tr>
	</table>
");

?>
