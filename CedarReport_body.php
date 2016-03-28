<?php
class CedarReport extends SpecialPage
{
    var $dbuser, $dbpwd ;

    function CedarReport() {
	SpecialPage::SpecialPage("CedarReport");
	#wfLoadExtensionMessages( 'CedarReport' ) ;

	$this->dbuser = "madrigal" ;
	$this->dbpwd = "shrot-kash-iv-po" ;
    }
    
    function execute( $par ) {
	global $wgRequest, $wgOut, $wgDBserver, $wgServer ;
	
	$this->setHeaders();

	$sort_param = $wgRequest->getText('sort');
	$sort_by = "request_time" ;
	if( $sort_param == "time" || $sort_param == "request_time" )
	{
	    $sort_by = "request_time" ;
	}
	else if( $sort_param == "user" )
	{
	    $sort_by = "user" ;
	}
	else if( $sort_param == "file" || $sort_param == "requested")
	{
	    $sort_by = "requested" ;
	}
	else if( $sort_param == "format" || $sort_param == "data_product" )
	{
	    $sort_by = "data_product" ;
	}
	$constraint['cuser'] = $wgRequest->getText( 'cuser' ) ;
	$constraint['cfile'] = $wgRequest->getText( 'cfile' ) ;
	$constraint['cformat'] = $wgRequest->getText( 'cformat' ) ;
	$constraint['cbdate'] = $wgRequest->getText( 'cbdate' ) ;
	$constraint['cedate'] = $wgRequest->getText( 'cedate' ) ;
	$cdistinct = $wgRequest->getCheck( 'cdistinct' ) ;
	if( $cdistinct ) $constraint['cdistinct'] = "true" ;
	else $constraint['cdistinct'] = "false" ;

	$action = $wgRequest->getText('action');
	if( $action == "csv" )
	{
	    $this->saveReport( $sort_by, $constraint ) ;
	}
	else if( $action == "pcsv" )
	{
	    $this->savePReport( $sort_by, $constraint ) ;
	}
	else
	{
	    $this->displayReport( $sort_by, $constraint ) ;
	}
    }

    private function displayReport( $sort_by, $constraint )
    {
	global $wgRequest, $wgOut, $wgDBserver, $wgServer, $wgUser ;
	global $cgReportExclude, $cgProtectedFileAccessLog ;

	// Connect to the CEDARCATALOG database
	$dbh = new DatabaseMysql( $wgDBserver, $this->dbuser, $this->dbpwd, "CEDARCATALOG" ) ;
	if( !$dbh )
	{
	    $wgOut->addHTML( "Unable to connect to the CEDAR Catalog database\n" ) ;
	    return ;
	}

	$allowed = $wgUser->isAllowed( 'cedar_admin' ) ;
	$instrs = array() ;
	$hasInstrs = false ;
	$pfiles = array() ;
	$hasProtectedFiles = false ;
	$uid = $wgUser->getId() ;
	// FIXME: Uncomment these two lines if testing a specific user
	//$allowed = false ;
	//$uid = 347 ;
	if( !$allowed && $uid != 0 )
	{
	    // get the list of instruments this person is allowed to see
	    $query = "SELECT LOWER(i.prefix) pref" ;
	    $query .= " FROM tbl_person p, tbl_person_role pr," ;
	    $query .= " tbl_role r, tbl_instrument i" ;
	    $query .= " WHERE p.user_id = $uid" ;
	    $query .= " AND p.person_id = pr.person_id" ;
	    $query .= " AND pr.role_id = r.role_id" ;
	    $query .= " AND r.role_name = 'InstrumentContact'" ;
	    $query .= " AND r.role_context = 'tbl_instrument'" ;
	    $query .= " AND pr.context_id = i.kinst" ;

	    // execute the query
	    //$wgOut->addHTML( "$query<br />\n" ) ;
	    $res = $dbh->query( $query ) ;
	    if( !$res )
	    {
		$db_error = $dbh->lastError() ;
		$dbh->close() ;
		$wgOut->addHTML( "Unable to query the CEDAR Catalog database<BR />\n" ) ;
		$wgOut->addHTML( $db_error ) ;
		$wgOut->addHTML( "<BR />\n" ) ;
		return ;
	    }

	    while( $obj = $dbh->fetchObject( $res ) )
	    {
		$hasInstrs = true ;
		$pref = $obj->pref ;
		$instrs[] = $pref ;
	    }
	    #$wgOut->addHTML( "INSTRS:<br />\n" ) ;
	    #foreach( $instrs as $i => $value )
	    #{
            #    $wgOut->addHTML( "$value<br />\n" ) ;
	    #}

	    // get the list of protected files this person is allowed to see
	    $query = "SELECT pr.context_id" ;
	    $query .= " FROM tbl_person p, tbl_person_role pr," ;
	    $query .= " tbl_role r" ;
	    $query .= " WHERE p.user_id = $uid" ;
	    $query .= " AND p.person_id = pr.person_id" ;
	    $query .= " AND pr.role_id = r.role_id" ;
	    $query .= " AND r.role_name = 'ProtectedTextContact'" ;
	    $query .= " AND r.role_context = 'tbl_protected_text'" ;

	    // execute the query
	    //$wgOut->addHTML( "$query<br />\n" ) ;
	    $res = $dbh->query( $query ) ;
	    if( !$res )
	    {
		$db_error = $dbh->lastError() ;
		$dbh->close() ;
		$wgOut->addHTML( "Unable to query the CEDAR Catalog database<BR />\n" ) ;
		$wgOut->addHTML( $db_error ) ;
		$wgOut->addHTML( "<BR />\n" ) ;
		return ;
	    }

	    while( $obj = $dbh->fetchObject( $res ) )
	    {
		$hasProtectedFiles = true ;
		$regex = $obj->context_id ;
		$pfiles[] = $regex ;
	    }
	    $wgOut->addHTML( "PFILES:<br />\n" ) ;
	    #foreach( $pfiles as $i => $value )
	    #{
            #    $wgOut->addHTML( "$value<br />\n" ) ;
	    #}
	}

	if( !$allowed && !$hasInstrs )
	{
	    return ;
	}

	$query = "SELECT DISTINCT data_product as data_product FROM tbl_report" ;

	// execute the query
	$res = $dbh->query( $query ) ;
	if( !$res )
	{
	    $db_error = $dbh->lastError() ;
	    $dbh->close() ;
	    $wgOut->addHTML( "Unable to query the CEDAR Catalog database<BR />\n" ) ;
	    $wgOut->addHTML( $db_error ) ;
	    $wgOut->addHTML( "<BR />\n" ) ;
	    return ;
	}

	$cuser = $constraint['cuser'] ;
	$cfile = $constraint['cfile'] ;
	$cformat = $constraint['cformat'] ;
	$cbdate = $constraint['cbdate'] ;
	$cedate = $constraint['cedate'] ;
	$cdistinct = $constraint['cdistinct'] ;
	$curl = "&cuser=$cuser&cfile=$cfile&cformat=$cformat&cbdate=$cbdate&cedate=$cedate" ;

	$isdistinct = false ;
	if( $cdistinct == "true" )
	{
	    $curl .= "&cdistinct=true" ;
	    $isdistinct = true ;
	    $whenwidth = "25%" ;
	    $userwidth = "25%" ;
	    $fileswidth = "25%" ;
	    $constraintwidth = "0%" ;
	    $formatwidth = "25%" ;
	}
	else
	{
	    $whenwidth = "10%" ;
	    $userwidth = "10%" ;
	    $fileswidth = "10%" ;
	    $constraintwidth = "60%" ;
	    $formatwidth = "10%" ;
	}

	$stime = "" ;
	$suser = "" ;
	$sfile = "" ;
	$sformat = "" ;
	if( $sort_by == "request_time" )
	    $stime = " *" ;
	else if( $sort_by == "user" )
	    $suser = " *" ;
	else if( $sort_by == "requested")
	    $sfile = " *" ;
	else if( $sort_by == "data_product" )
	    $sformat = " *" ;

	$wgOut->addWikiText( "==Constrain the report==\n") ;
	$wgOut->addHTML( "<form action='$wgServer/wiki/index.php/Special:CedarReport?action=sort&sort=$sort_by'>\n") ;
	$wgOut->addHTML( "<input type='hidden' name='sort' value='$sort_by'>\n") ;
	$wgOut->addHTML( "<table width='400' cellpadding='2' cellspacing='2' border='0'>\n") ;
	$wgOut->addHTML( "<tr>\n") ;
	$wgOut->addHTML( "<td width='150' align='right' valign='middle'>\n") ;
	$wgOut->addHTML( "Distinct Selection:&nbsp;&nbsp;\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "<td width='250' align='left' valign='middle'>\n") ;
	$ischecked = "" ;
	if( $cdistinct == "true" ) $ischecked = "CHECKED" ;
	$wgOut->addHTML( "<input type='checkbox' name='cdistinct' value='true' $ischecked>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "</tr>\n") ;
	$wgOut->addHTML( "<tr>\n") ;
	$wgOut->addHTML( "<td width='150' align='right' valign='middle'>\n") ;
	$wgOut->addHTML( "Beginning Date:&nbsp;&nbsp;<br>\n") ;
	$wgOut->addHTML( "<span style='font-size:8pt;'>(yyyy-mm-dd)&nbsp;&nbsp;</span>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "<td width='250' align='left' valign='middle'>\n") ;
	$wgOut->addHTML( "<input type='text' size='30' name='cbdate' value='$cbdate'>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "</tr>\n") ;
	$wgOut->addHTML( "<tr>\n") ;
	$wgOut->addHTML( "<td width='150' align='right' valign='middle'>\n") ;
	$wgOut->addHTML( "Ending Date:&nbsp;&nbsp;<br>\n") ;
	$wgOut->addHTML( "<span style='font-size:8pt;'>(yyyy-mm-dd)&nbsp;&nbsp;</span>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "<td width='250' align='left' valign='middle'>\n") ;
	$wgOut->addHTML( "<input type='text' size='30' name='cedate' value='$cedate'>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "</tr>\n") ;
	$wgOut->addHTML( "<tr>\n") ;
	$wgOut->addHTML( "<td width='150' align='right' valign='middle'>\n") ;
	$wgOut->addHTML( "User:&nbsp;&nbsp;\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "<td width='250' align='left' valign='middle'>\n") ;
	$wgOut->addHTML( "<input type='text' size='30' name='cuser' value='$cuser'>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "</tr>\n") ;
	$wgOut->addHTML( "<tr>\n") ;
	$wgOut->addHTML( "<td width='150' align='right' valign='middle'>\n") ;
	$wgOut->addHTML( "Files:&nbsp;&nbsp;<br>\n") ;
	$wgOut->addHTML( "<span style='font-size:8pt;'>(first 3 chars, i.e. mfp)&nbsp;&nbsp;</span>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "<td width='250' align='left' valign='middle'>\n") ;
	$wgOut->addHTML( "<input type='text' size='30' name='cfile' value='$cfile'>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "</tr>\n") ;
	$wgOut->addHTML( "<tr>\n") ;
	$wgOut->addHTML( "<td width='150' align='right' valign='middle'>\n") ;
	$wgOut->addHTML( "Format:&nbsp;&nbsp;\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "<td width='250' align='left' valign='middle'>\n") ;
	$wgOut->addHTML( "<select name='cformat' size='1'>\n") ;
	if( $cformat == "" )
	    $wgOut->addHTML( "<option value='' selected>none</option>\n") ;
	else
	    $wgOut->addHTML( "<option value=''>none</option>\n") ;
	while( ( $obj = $dbh->fetchObject( $res ) ) )
	{
	    $format = trim( $obj->data_product ) ;
	    $selected = "" ;
	    if( $format == $cformat )
		$selected = "selected" ;
	    $wgOut->addHTML( "<option value='$format' $selected>$format</option>\n") ;
	}
	$wgOut->addHTML( "</select>\n") ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "</tr>\n") ;
	$wgOut->addHTML( "<tr>\n") ;
	$wgOut->addHTML( "<td width='150' align='right' valign='middle'>\n") ;
	$wgOut->addHTML( "<input type='submit' name='submit' value='Submit'>\n" ) ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "<td width='250' align='left' valign='middle'>\n") ;
	$wgOut->addHTML( "<input type='reset' name='reset' value='Reset'>\n" ) ;
	$wgOut->addHTML( "</td>\n") ;
	$wgOut->addHTML( "</tr>\n") ;
	$wgOut->addHTML( "</table>\n") ;
	$wgOut->addHTML( "</form>\n") ;

	$wgOut->addWikiText( "==Database Access Log==" ) ;

	// allow user to download as csv
	$wgOut->addHTML( "Save as <a href='$wgServer/wiki/index.php/Special:CedarReport?action=csv&sort=$sort_by$curl'>CSV file</a><br><br>\n" ) ;

	$query = $this->build_query( $dbh, $sort_by, $constraint, $isdistinct );

	// execute the query
	$res = $dbh->query( $query ) ;
	if( !$res )
	{
	    $db_error = $dbh->lastError() ;
	    $dbh->close() ;
	    $wgOut->addHTML( "Unable to query the CEDAR Catalog database<BR />\n" ) ;
	    $wgOut->addHTML( $db_error ) ;
	    $wgOut->addHTML( "<BR />\n" ) ;
	    return ;
	}

	// display the results
	$wgOut->addHTML( "    <TABLE ALIGN=\"CENTER\" BORDER=\"1\" WIDTH=\"100%\" CELLPADDING=\"4\" CELLSPACING=\"0\">\n" ) ;
	$wgOut->addHTML( "	<TR style=\"background-color:gainsboro;\">\n" ) ;
	$wgOut->addHTML( "	    <TD WIDTH=\"$whenwidth\" ALIGN=\"CENTER\">\n" ) ;
	$wgOut->addHTML( "		<SPAN STYLE=\"font-weight:bold;font-size:11pt;\"><A HREF='$wgServer/wiki/index.php/Special:CedarReport?action=sort&sort=time$curl'>When Requested$stime</A></SPAN>\n" ) ;
	$wgOut->addHTML( "	    </TD>\n" ) ;
	$wgOut->addHTML( "	    <TD WIDTH=\"$userwidth\" ALIGN=\"CENTER\">\n" ) ;
	$wgOut->addHTML( "		<SPAN STYLE=\"font-weight:bold;font-size:11pt;\"><A HREF='$wgServer/wiki/index.php/Special:CedarReport?action=sort&sort=user$curl'>User$suser</A></SPAN>\n" ) ;
	$wgOut->addHTML( "	    </TD>\n" ) ;
	$wgOut->addHTML( "	    <TD WIDTH=\"$fileswidth\" ALIGN=\"CENTER\">\n" ) ;
	$wgOut->addHTML( "		<SPAN STYLE=\"font-weight:bold;font-size:11pt;\"><A HREF='$wgServer/wiki/index.php/Special:CedarReport?action=sort&sort=file$curl'>Files<br />Requested$sfile</A></SPAN>\n" ) ;
	$wgOut->addHTML( "	    </TD>\n" ) ;
	if( $cdistinct != "true" )
	{
	    $wgOut->addHTML( "	    <TD WIDTH=\"$constraintwidth\" ALIGN=\"CENTER\">\n" ) ;
	    $wgOut->addHTML( "		Constraint\n" ) ;
	    $wgOut->addHTML( "	    </TD>\n" ) ;
	}
	$wgOut->addHTML( "	    <TD WIDTH=\"10%\" ALIGN=\"CENTER\">\n" ) ;
	$wgOut->addHTML( "		<SPAN STYLE=\"font-weight:bold;font-size:11pt;\"><A HREF='$wgServer/wiki/index.php/Special:CedarReport?action=sort&sort=format$curl'>Format$sformat</A></SPAN>\n" ) ;
	$wgOut->addHTML( "	    </TD>\n" ) ;
	$wgOut->addHTML( "	</TR>\n" ) ;
	$rowcolor="white" ;
	while( ( $obj = $dbh->fetchObject( $res ) ) )
	{
	    $request_time = trim( $obj->request_time ) ;
	    $user_name = trim( $obj->user ) ;
	    $requested = trim( $obj->requested ) ;
	    $constraint = trim( $obj->constraint_expr ) ;
	    $format = trim( $obj->data_product ) ;
	    if( $allowed || $this->instrAllowed( $requested, $instrs ) )
	    {
		$wgOut->addHTML( "	<TR style=\"background-color:$rowcolor;\">\n" ) ;
		if( $rowcolor == "white" ) $rowcolor = "gainsboro" ;
		else $rowcolor = "white" ;
		$wgOut->addHTML( "	    <TD WIDTH=\"$whenwidth\" ALIGN=\"LEFT\">\n" ) ;
		$wgOut->addHTML( "	        $request_time\n" ) ;
		$wgOut->addHTML( "	    </TD>\n" ) ;
		$wgOut->addHTML( "	    <TD WIDTH=\"$userwidth\" ALIGN=\"LEFT\">\n" ) ;
		$user_display = $this->getUserInfoHTML( $user_name ) ;
		$wgOut->addHTML( "		$user_display\n" ) ;
		$wgOut->addHTML( "	    </TD>\n" ) ;
		$wgOut->addHTML( "	    <TD WIDTH=\"$fileswidth\" ALIGN=\"LEFT\">\n" ) ;
		if( $requested != "" )
		{
		    $rlist = explode( ",", $requested ) ;
		    foreach( $rlist as $i => $value )
		    {
			$wgOut->addHTML( "$value<br>\n" ) ;
		    }
		}
		else
		{
		    $wgOut->addHTML( "		$requested\n" ) ;
		}
		$wgOut->addHTML( "	    </TD>\n" ) ;
		if( $cdistinct != "true" )
		{
		    $wgOut->addHTML( "	    <TD WIDTH=\"60%\" ALIGN=\"LEFT\">\n" ) ;
		    if( $constraint != "" )
		    {
			$clist = explode( ";", $constraint ) ;
			foreach( $clist as $i => $value )
			{
			    $done = false ;
			    $isfirst = true ;
			    while( !$done )
			    {
				if( strlen( $value ) > 100 )
				{
				    $cvalue = substr( $value, 0, 99 ) ;
				    $value = substr( $value, 99 ) ;
				    if( !$isfirst ) $wgOut->addHTML( "&nbsp;&nbsp;&nbsp;&nbsp;" ) ;
				    else $isfirst = false ;
				    $wgOut->addHTML( "$cvalue<br>\n" ) ;
				}
				else
				{
				    if( !$isfirst ) $wgOut->addHTML( "&nbsp;&nbsp;&nbsp;&nbsp;" ) ;
				    else $isfirst = false ;
				    $wgOut->addHTML( "$value<br>\n" ) ;
				    $done = true ;
				}
			    }
			}
		    }
		    else
		    {
			$wgOut->addHTML( "		$constraint\n" ) ;
		    }
		    $wgOut->addHTML( "	    </TD>\n" ) ;
		}
		$wgOut->addHTML( "	    <TD WIDTH=\"$formatwidth\" ALIGN=\"LEFT\">\n" ) ;
		$wgOut->addHTML( "		$format\n" ) ;
		$wgOut->addHTML( "	    </TD>\n" ) ;
		$wgOut->addHTML( "	</TR>\n" ) ;
	    }
	}
	$wgOut->addHTML( "</TABLE>\n" ) ;
	$dbh->close() ;

	if( !$allowed && !$hasProtectedFiles )
	{
	    return ;
	}

	// Now let's write out the access log for protected files
	$wgOut->addHTML( "<br><br>\n" ) ;
	$wgOut->addWikiText( "==Protected File Access Log==" ) ;

	// allow user to download as csv
	$wgOut->addHTML( "Save as <a href='$wgServer/wiki/index.php/Special:CedarReport?action=pcsv&sort=$sort_by'>CSV file</a><br><br>\n" ) ;

	// open the log file
	$handle = fopen( $cgProtectedFileAccessLog, "r" ) ;
	if( !$handle )
	{
	    $wgOut->addHTML( "Failed to open the access log file $cgProtectedFileAccessLog\n" ) ;
	    return ;
	}

	//format of the file as of Feb 12, 2010
	//[Fri Mar  6, 2009 02:27:27] User pwest accessed file /project/cedar/html/protected/timed/rdtab.pro

	$wgOut->addHTML( "    <TABLE ALIGN=\"CENTER\" BORDER=\"1\" WIDTH=\"100%\" CELLPADDING=\"4\" CELLSPACING=\"0\">\n" ) ;
	$wgOut->addHTML( "	<TR style=\"background-color:gainsboro;\">\n" ) ;
	$wgOut->addHTML( "	    <TD WIDTH=\"10%\" ALIGN=\"CENTER\">\n" ) ;
	$wgOut->addHTML( "		<SPAN STYLE=\"font-weight:bold;font-size:11pt;\">When Requested</SPAN>\n" ) ;
	$wgOut->addHTML( "	    </TD>\n" ) ;
	$wgOut->addHTML( "	    <TD WIDTH=\"10%\" ALIGN=\"CENTER\">\n" ) ;
	$wgOut->addHTML( "		<SPAN STYLE=\"font-weight:bold;font-size:11pt;\">User</SPAN>\n" ) ;
	$wgOut->addHTML( "	    </TD>\n" ) ;
	$wgOut->addHTML( "	    <TD WIDTH=\"80%\" ALIGN=\"CENTER\">\n" ) ;
	$wgOut->addHTML( "		<SPAN STYLE=\"font-weight:bold;font-size:11pt;\">File Requested</SPAN>\n" ) ;
	$wgOut->addHTML( "	    </TD>\n" ) ;
	$wgOut->addHTML( "	</TR>\n" ) ;

	$rowcolor="white" ;
	while( $line = fgets( $handle ) )
	{
	    $line = trim( $line ) ;
	    $arr = explode( " ", $line ) ;
	    $count = count( $arr ) ;
	    if( $count == 10 || $count == 11 )
	    {
		$dow = substr( $arr[0], 1 ) ;
		$mon = $this->convertMon( $arr[1] ) ;
		if( $count == 10 )
		{
		    $day = substr( $arr[2], 0, strlen( $arr[2] ) - 1 ) ;
		    $yr = $arr[3] ;
		    $time = substr( $arr[4], 0, strlen( $arr[4] ) - 1 ) ;
		    $user_name = $arr[6] ;
		    $file = $arr[9] ;
		}
		else
		{
		    $day = substr( $arr[3], 0, strlen( $arr[3] ) - 1 ) ;
		    $yr = $arr[4] ;
		    $time = substr( $arr[5], 0, strlen( $arr[5] ) - 1 ) ;
		    $user_name = $arr[7] ;
		    $file = $arr[10] ;
		}
		if( $this->includeFileUser( $user_name ) && ( $allowed || $this->pfileAllowed( $file, $pfiles ) ) )
		{
		    $dt = "$yr-$mon-$day $time" ;
		    $wgOut->addHTML( "	<TR style=\"background-color:$rowcolor;\">\n" ) ;
		    if( $rowcolor == "white" ) $rowcolor = "gainsboro" ;
		    else $rowcolor = "white" ;
		    $wgOut->addHTML( "	    <TD WIDTH=\"10%\" ALIGN=\"LEFT\">\n" ) ;
		    $wgOut->addHTML( "	        $dt\n" ) ;
		    $wgOut->addHTML( "	    </TD>\n" ) ;
		    $wgOut->addHTML( "	    <TD WIDTH=\"10%\" ALIGN=\"LEFT\">\n" ) ;
		    $user_display = $this->getUserInfoHTML( $user_name ) ;
		    $wgOut->addHTML( "		$user_display\n" ) ;
		    $wgOut->addHTML( "	    </TD>\n" ) ;
		    $wgOut->addHTML( "	    <TD WIDTH=\"80%\" ALIGN=\"LEFT\">\n" ) ;
		    $wgOut->addHTML( "	        $file\n" ) ;
		    $wgOut->addHTML( "	    </TD>\n" ) ;
		    $wgOut->addHTML( "	</TR>\n" ) ;
		}
	    }
	}
	$wgOut->addHTML( "</TABLE>\n" ) ;
    }

    private function includeFileUser( $user_name )
    {
	global $cgFileReportExclude ;

	$doinclude = true ;
	if( count( $cgFileReportExclude ) )
	{
	    foreach( $cgFileReportExclude as $i => $value )
	    {
		if( $user_name == $value ) $doinclude = false ;
	    }
	}
	return $doinclude ;
    }

    private function saveReport( $sort_by, $constraint )
    {
	global $wgRequest, $wgOut, $wgDBserver, $wgServer, $wgUser ;
	global $cgReportExclude, $cgReportDir, $cgProtectedFileAccessLog ;

	// make sure this user is allowed to view this page
	$allowed = $wgUser->isAllowed( 'cedar_admin' ) ;
	if( !$allowed )
	{
	    $wgOut->addHTML( "You do not have permission to view the CEDAR database report\n" ) ;
	    return ;
	}

	$cuser = $constraint['cuser'] ;
	$cfile = $constraint['cfile'] ;
	$cformat = $constraint['cformat'] ;
	$cbdate = $constraint['cbdate'] ;
	$cedate = $constraint['cedate'] ;
	$cdistinct = $constraint['cdistinct'] ;
	$curl = "&cuser=$cuser&cfile=$cfile&cformat=$cformat&cbdate=$cbdate&cedate=$cedate" ;
	$isdistinct = false ;
	if( $cdistinct == "true" )
	{
	    $curl .= "&cdistinct=true" ;
	    $isdistinct = true ;
	}

	// create a file name using the date and time. Be sure we can open it
	// and write to it
	$sdate = date( "ymd-His" ) ;
	$filename = "$cgReportDir/data_access_report-$sdate.csv" ;
	$handle = @fopen( $filename, "w" ) ;
	if( !$handle )
	{
	    $wgOut->addHTML( "Unable to save the report to $filename<br>\n" ) ;
	    $wgOut->addHTML( "$php_errormsg<br><br>\n" ) ;
	    return ;
	}

	// Connect to the CEDARCATALOG database
	$dbh = new DatabaseMysql( $wgDBserver, $this->dbuser, $this->dbpwd, "CEDARCATALOG" ) ;
	if( !$dbh )
	{
	    $wgOut->addHTML( "Unable to connect to the CEDAR Catalog database\n" ) ;
	    @fclose( $handle ) ;
	    return ;
	}

	$query = $this->build_query( $dbh, $sort_by, $constraint, $isdistinct );

	// execute the query
	$res = $dbh->query( $query ) ;
	if( !$res )
	{
	    $db_error = $dbh->lastError() ;
	    $dbh->close() ;
	    $wgOut->addHTML( "Unable to query the CEDAR Catalog database<BR />\n" ) ;
	    $wgOut->addHTML( $db_error ) ;
	    $wgOut->addHTML( "<BR />\n" ) ;
	    @fclose( $handle ) ;
	    return ;
	}

	while( ( $obj = $dbh->fetchObject( $res ) ) )
	{
	    $request_time = trim( $obj->request_time ) ;
	    $user_name = trim( $obj->user ) ;
	    $user_real_name = "" ;
	    $user_email = "" ;
	    $this->getUserInfo( $user_name, $user_real_name, $user_email ) ;
	    $requested = trim( $obj->requested ) ;
	    $constraint = trim( $obj->constraint_expr ) ;
	    $format = trim( $obj->data_product ) ;
	    if( $isdistinct == true )
	    {
		fwrite( $handle, "$request_time, \"$user_name\", \"$user_real_name\", \"$user_email\", \"$requested\", \"$format\"\n" ) ;
	    }
	    else
	    {
		fwrite( $handle, "$request_time, \"$user_name\", \"$user_real_name\", \"$user_email\", \"$requested\", \"$constraint\", \"$format\"\n" ) ;
	    }
	}
	@fclose( $handle ) ;
	$wgOut->addHTML( "Database Access Report saved to $filename<br><br>\n" ) ;
	$wgOut->addHTML( "\n" ) ;
	$wgOut->addHTML( "<A HREF='$wgServer/wiki/index.php/Special:CedarReport?action=sort&sort=$sort_by$curl'>Return to Report Page</A>\n" ) ;
    }

    private function savePReport( $sort_by, $constraint )
    {
	global $wgRequest, $wgOut, $wgDBserver, $wgServer, $wgUser ;
	global $cgReportExclude, $cgReportDir, $cgProtectedFileAccessLog ;

	// make sure this user is allowed to view this page
	$allowed = $wgUser->isAllowed( 'cedar_admin' ) ;
	if( !$allowed )
	{
	    $wgOut->addHTML( "You do not have permission to view the CEDAR database report\n" ) ;
	    return ;
	}

	$cuser = $constraint['cuser'] ;
	$cfile = $constraint['cfile'] ;
	$cformat = $constraint['cformat'] ;
	$cbdate = $constraint['cbdate'] ;
	$cedate = $constraint['cedate'] ;
	$curl = "&cuser=$cuser&cfile=$cfile&cformat=$cformat&cbdate=$cbdate&cedate=$cedate" ;

	// open the log file
	$handle = fopen( $cgProtectedFileAccessLog, "r" ) ;
	if( !$handle )
	{
	    $wgOut->addHTML( "Failed to open the access log file $cgProtectedFileAccessLog\n" ) ;
	    return ;
	}

	// create a file name using the date and time. Be sure we can open it
	// and write to it
	$sdate = date( "ymd-His" ) ;
	$filename = "$cgReportDir/protected_access_report-$sdate.csv" ;
	$ohandle = @fopen( $filename, "w" ) ;
	if( !$ohandle )
	{
	    $wgOut->addHTML( "Unable to save the report to $filename<br>\n" ) ;
	    $wgOut->addHTML( "$php_errormsg<br><br>\n" ) ;
	    return ;
	}

	while( $line = fgets( $handle ) )
	{
	    $line = trim( $line ) ;
	    $arr = explode( " ", $line ) ;
	    $count = count( $arr ) ;
	    if( $count == 10 || $count == 11 )
	    {
		$dow = substr( $arr[0], 1 ) ;
		$mon = $this->convertMon( $arr[1] ) ;
		if( $count == 10 )
		{
		    $day = substr( $arr[2], 0, strlen( $arr[2] ) - 1 ) ;
		    $yr = $arr[3] ;
		    $time = substr( $arr[4], 0, strlen( $arr[4] ) - 1 ) ;
		    $user_name = $arr[6] ;
		    $file = $arr[9] ;
		}
		else
		{
		    $day = substr( $arr[3], 0, strlen( $arr[3] ) - 1 ) ;
		    $yr = $arr[4] ;
		    $time = substr( $arr[5], 0, strlen( $arr[5] ) - 1 ) ;
		    $user_name = $arr[7] ;
		    $file = $arr[10] ;
		}
		if( $this->includeFileUser( $user_name ) )
		{
		    $user_real_name = "" ;
		    $user_email = "" ;
		    $this->getUserInfo( $user_name, $user_real_name, $user_email ) ;
		    $dt = "$yr-$mon-$day $time" ;
		    fwrite( $ohandle, "$dt, \"$user_name\", \"$user_real_name\", \"$user_email\", \"$file\"\n" ) ;
		}
	    }
	}
	@fclose( $ohandle ) ;
	@fclose( $handle ) ;
	$wgOut->addHTML( "Protected File Access Report saved to $filename<br><br>\n" ) ;
	$wgOut->addHTML( "<A HREF='$wgServer/wiki/index.php/Special:CedarReport?action=sort&sort=$sort_by$curl'>Return to Report Page</A>\n" ) ;
    }

    private function convertMon( $mon )
    {
	$retval = $mon ;
	if( $mon == "Jan" ) $retval = "01" ;
	if( $mon == "Feb" ) $retval = "02" ;
	if( $mon == "Mar" ) $retval = "03" ;
	if( $mon == "Apr" ) $retval = "04" ;
	if( $mon == "May" ) $retval = "05" ;
	if( $mon == "Jun" ) $retval = "06" ;
	if( $mon == "Jul" ) $retval = "07" ;
	if( $mon == "Aug" ) $retval = "08" ;
	if( $mon == "Sep" ) $retval = "09" ;
	if( $mon == "Oct" ) $retval = "10" ;
	if( $mon == "Nov" ) $retval = "11" ;
	if( $mon == "Dec" ) $retval = "12" ;
	return $retval ;
    }

    private function getUserInfoHTML( $user_name )
    {
	$retval = "" ;
	$email = "" ;
	$real_name = "" ;
	$this->getUserInfo( $user_name, $real_name, $email ) ;
	if( $email && $email != "" )
	{
	    $retval = "<a href=\"mailto:$email\">$user_name</a>" ;
	}
	else
	{
	    $retval = "$user_name" ;
	}

	if( $real_name && $real_name != "" )
	{
	    $retval .= "<br>$real_name" ;
	}

	return $retval ;
    }

    private function getUserInfo( $user_name, &$user_real_name, &$user_email )
    {
	$u = User::newFromName( $user_name ) ;
	if( $u && $u->getId() != 0 )
	{
	    $user_real_name = $u->getRealName() ;
	    $user_email = $u->getEmail() ;
	}
    }

    private function build_query( $dbh, $sort_by, $constraint, $isdistinct )
    {
	global $wgRequest, $wgOut, $wgDBserver, $wgServer, $wgUser ;
	global $cgReportExclude, $cgProtectedFileAccessLog ;

	// build the where clause. This will exclude the list of people in
	// the cgReportExclude variable from LocalSettings.php
	$where = "WHERE requested != \"\"" ;
	if( count( $cgReportExclude ) )
	{
	    $where .= " AND user not in (" ;
	    $isfirst = true ;
	    foreach( $cgReportExclude as $i => $value )
	    {
		if( !$isfirst ) $where .= ", " ;
		else $isfirst = false ;
		$clean_value = $dbh->strencode( $value ) ;
		$where .= "\"$clean_value\"" ;
	    }
	    $where .= ")" ;
	}
	$cuser = $dbh->strencode( ucfirst( trim( $constraint['cuser'] ) ) ) ;
	$cfile = $dbh->strencode( trim( $constraint['cfile'] ) ) ;
	$cformat = $dbh->strencode( trim( $constraint['cformat'] ) ) ;
	$cbdate = $dbh->strencode( trim( $constraint['cbdate'] ) ) ;
	$cedate = $dbh->strencode( trim( $constraint['cedate'] ) ) ;
	if( $cuser != "" )
	{
	    $where .= " AND user = \"$cuser\"" ;
	}
	if( $cfile != "" )
	{
	    if( strlen( $cfile ) > 3 ) $cfile = substr( $cfile, 0, 3 ) ;
	    $cfile = strtolower( $cfile ) ;
	    $where .= " AND SUBSTR( requested, 1, 3 ) =  \"$cfile\"" ;
	}
	if( $cformat != "" )
	{
	    $cformat = strtolower( $cformat ) ;
	    $where .= " AND data_product = \"$cformat\"" ;
	}
	if( $cbdate != "" )
	{
	    $where .= " AND date( request_time ) >=  '$cbdate'" ;
	}
	if( $cedate != "" )
	{
	    $where .= " AND date( request_time ) <=  '$cedate'" ;
	}

	// build the query
	if( $isdistinct )
	{
	    $query = "SELECT DISTINCT date( request_time ) request_time" ;
	    $query .= ", user, substr( requested, 1, 3 ) requested" ;
	    $query .= ", data_product" ;
	}
	else
	{
	    $query = "SELECT request_time,user,requested,constraint_expr" ;
	    $query .= ",data_product" ;
	}
	$query .= " FROM tbl_report" ;
	$query .= " $where" ;
	$clean_sort_by = $dbh->strencode( $sort_by ) ;
	$query .= " ORDER BY $clean_sort_by" ;

	return $query ;
    }

    private function instrAllowed( $requested, $instrs )
    {
	$retval = false ;
	$pref=substr( $requested, 0, 3 ) ;
	foreach( $instrs as $instr )
	{
	    if( $instr == $pref )
	    {
		$retval = true ;
	    }
	}

	return $retval ;
    }

    private function pfileAllowed( $requested, $pfiles )
    {
	$retval = false ;
	foreach( $pfiles as $pfile )
	{
	    $pos = strpos( $requested, $pfile ) ;
	    if( $pos != false )
	    {
		$retval = true ;
	    }
	}

	return $retval ;
    }
}
?>
