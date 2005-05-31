<?php
/**
 *
 * @package MediaWiki
 * @subpackage SpecialPage
 */

/**
 *
 */
require_once( 'Image.php' );

/**
 * Entry point
 */
function wfSpecialUpload() {
	global $wgRequest;
	$form = new UploadForm( $wgRequest );
	$form->execute();
}

/**
 *
 * @package MediaWiki
 * @subpackage SpecialPage
 */
class UploadForm {
	/**#@+
	 * @access private
	 */
	var $mUploadAffirm, $mUploadFile, $mUploadDescription, $mIgnoreWarning;
	var $mUploadSaveName, $mUploadTempName, $mUploadSize, $mUploadOldVersion;
	var $mUploadCopyStatus, $mUploadSource, $mReUpload, $mAction, $mUpload;
	var $mOname, $mSessionKey, $mStashed, $mDestFile;
	/**#@-*/

	/**
	 * Constructor : initialise object
	 * Get data POSTed through the form and assign them to the object
	 * @param $request Data posted.
	 */
	function UploadForm( &$request ) {
		$this->mDestFile          = $request->getText( 'wpDestFile' );
		
		if( !$request->wasPosted() ) {
			# GET requests just give the main form; no data except wpDestfile.
			return;
		}

		$this->mUploadAffirm      = $request->getCheck( 'wpUploadAffirm' );
		$this->mIgnoreWarning     = $request->getCheck( 'wpIgnoreWarning');
		$this->mReUpload          = $request->getCheck( 'wpReUpload' );
		$this->mUpload            = $request->getCheck( 'wpUpload' );
		
		$this->mUploadDescription = $request->getText( 'wpUploadDescription' );
		$this->mUploadCopyStatus  = $request->getText( 'wpUploadCopyStatus' );
		$this->mUploadSource      = $request->getText( 'wpUploadSource');
		
		$this->mAction            = $request->getVal( 'action' );
		
		$this->mSessionKey        = $request->getInt( 'wpSessionKey' );
		if( !empty( $this->mSessionKey ) &&
			isset( $_SESSION['wsUploadData'][$this->mSessionKey] ) ) {
			/**
			 * Confirming a temporarily stashed upload.
			 * We don't want path names to be forged, so we keep
			 * them in the session on the server and just give
			 * an opaque key to the user agent.
			 */
			$data = $_SESSION['wsUploadData'][$this->mSessionKey];
			$this->mUploadTempName   = $data['mUploadTempName'];
			$this->mUploadSize       = $data['mUploadSize'];
			$this->mOname            = $data['mOname'];
			$this->mStashed	 	 = true;
		} else {
			/**
			 *Check for a newly uploaded file.
			 */
			$this->mUploadTempName = $request->getFileTempName( 'wpUploadFile' );
			$this->mUploadSize     = $request->getFileSize( 'wpUploadFile' );
			$this->mOname          = $request->getFileName( 'wpUploadFile' );
			$this->mSessionKey     = false;
			$this->mStashed        = false;
		}
	}

	/**
	 * Start doing stuff
	 * @access public
	 */
	function execute() {
		global $wgUser, $wgOut;
		global $wgEnableUploads, $wgUploadDirectory;

		/** Show an error message if file upload is disabled */ 
		if( ! $wgEnableUploads ) {
			$wgOut->addWikiText( wfMsg( 'uploaddisabled' ) );
			return;
		}

		/** Various rights checks */
		if( ( $wgUser->isAnon() )
			 OR $wgUser->isBlocked() ) {
			$wgOut->errorpage( 'uploadnologin', 'uploadnologintext' );
			return;
		}
		if( wfReadOnly() ) {
			$wgOut->readOnlyPage();
			return;
		}
		
		/** Check if the image directory is writeable, this is a common mistake */
		if ( !is_writeable( $wgUploadDirectory ) ) {
			$wgOut->addWikiText( wfMsg( 'upload_directory_read_only', $wgUploadDirectory ) );
			return;
		}

		if( $this->mReUpload ) {
			$this->unsaveUploadedFile();
			$this->mainUploadForm();
		} else if ( 'submit' == $this->mAction || $this->mUpload ) {
			$this->processUpload();
		} else {
			$this->mainUploadForm();
		}
	}

	/* -------------------------------------------------------------- */

	/**
	 * Really do the upload
	 * Checks are made in SpecialUpload::execute()
	 * @access private
	 */
	function processUpload() {
		global $wgUser, $wgOut, $wgLang, $wgContLang;
		global $wgUploadDirectory;
		global $wgUseCopyrightUpload, $wgCheckCopyrightUpload;

		/**
		 * If there was no filename or a zero size given, give up quick.
		 */
		if( trim( $this->mOname ) == '' || empty( $this->mUploadSize ) ) {
			return $this->mainUploadForm('<li>'.wfMsg( 'emptyfile' ).'</li>');
		}
		
		/**
		 * When using detailed copyright, if user filled field, assume he
		 * confirmed the upload
		 */
		if ( $wgUseCopyrightUpload ) {
			$this->mUploadAffirm = true;
			if( $wgCheckCopyrightUpload && 
			    ( trim( $this->mUploadCopyStatus ) == '' ||
			      trim( $this->mUploadSource     ) == '' ) ) {
				$this->mUploadAffirm = false;
			}
		}

		/** User need to confirm his upload */
		if( !$this->mUploadAffirm ) {
			$this->mainUploadForm( wfMsg( 'noaffirmation' ) );
			return;
		}

		# Chop off any directories in the given filename
		if ( $this->mDestFile ) {
			$basename = basename( $this->mDestFile );
		} else {
			$basename = basename( $this->mOname );
		}

		/**
		 * We'll want to blacklist against *any* 'extension', and use
		 * only the final one for the whitelist.
		 */
		list( $partname, $ext ) = $this->splitExtensions( $basename );
		if( count( $ext ) ) {
			$finalExt = $ext[count( $ext ) - 1];
		} else {
			$finalExt = '';
		}
		$fullExt = implode( '.', $ext );
		
		if ( strlen( $partname ) < 3 ) {
			$this->mainUploadForm( wfMsg( 'minlength' ) );
			return;
		}

		/**
		 * Filter out illegal characters, and try to make a legible name
		 * out of it. We'll strip some silently that Title would die on.
		 */
		$filtered = preg_replace ( "/[^".Title::legalChars()."]|:/", '-', $basename );
		$nt = Title::newFromText( $filtered );
		if( is_null( $nt ) ) {
			return $this->uploadError( wfMsg( 'illegalfilename', htmlspecialchars( $filtered ) ) );
		}
		$nt =& Title::makeTitle( NS_IMAGE, $nt->getDBkey() );
		$this->mUploadSaveName = $nt->getDBkey();
		
		/**
		 * If the image is protected, non-sysop users won't be able
		 * to modify it by uploading a new revision.
		 */
		if( !$nt->userCanEdit() ) {
			return $this->uploadError( wfMsg( 'protectedpage' ) );
		}
		
		/* Don't allow users to override the blacklist (check file extension) */
		global $wgStrictFileExtensions;
		global $wgFileExtensions, $wgFileBlacklist;
		if( $this->checkFileExtensionList( $ext, $wgFileBlacklist ) ||
			($wgStrictFileExtensions &&
				!$this->checkFileExtension( $finalExt, $wgFileExtensions ) ) ) {
			return $this->uploadError( wfMsg( 'badfiletype', htmlspecialchars( $fullExt ) ) );
		}
		
		/**
		 * Look at the contents of the file; if we can recognize the
		 * type but it's corrupt or data of the wrong type, we should
		 * probably not accept it.
		 */
		if( !$this->mStashed ) {
			$veri= $this->verify($this->mUploadTempName, $finalExt);
			
			if( $veri !== true ) { //it's a wiki error...
				return $this->uploadError( $veri->toString() );
			}
		}
		
		/**
		 * Check for non-fatal conditions
		 */
		if ( ! $this->mIgnoreWarning ) {
			$warning = '';
			if( $this->mUploadSaveName != ucfirst( $filtered ) ) {
				$warning .=  '<li>'.wfMsg( 'badfilename', htmlspecialchars( $this->mUploadSaveName ) ).'</li>';
			}
	
			global $wgCheckFileExtensions;
			if ( $wgCheckFileExtensions ) {
				if ( ! $this->checkFileExtension( $finalExt, $wgFileExtensions ) ) {
					$warning .= '<li>'.wfMsg( 'badfiletype', htmlspecialchars( $fullExt ) ).'</li>';
				}
			}
	
			global $wgUploadSizeWarning;
			if ( $wgUploadSizeWarning && ( $this->mUploadSize > $wgUploadSizeWarning ) ) {
				# TODO: Format $wgUploadSizeWarning to something that looks better than the raw byte
				# value, perhaps add GB,MB and KB suffixes?
				$warning .= '<li>'.wfMsg( 'largefile', $wgUploadSizeWarning, $this->mUploadSize ).'</li>';
			}
			if ( $this->mUploadSize == 0 ) {
				$warning .= '<li>'.wfMsg( 'emptyfile' ).'</li>';
			}
			
			if( $nt->getArticleID() ) {
				global $wgUser;
				$sk = $wgUser->getSkin();
				$dlink = $sk->makeKnownLinkObj( $nt );
				$warning .= '<li>'.wfMsg( 'fileexists', $dlink ).'</li>';
			}
			
			if( $warning != '' ) {
				/**
				 * Stash the file in a temporary location; the user can choose
				 * to let it through and we'll complete the upload then.
				 */
				return $this->uploadWarning($warning);
			}
		}
		
		/**
		 * Try actually saving the thing...
		 * It will show an error form on failure.
		 */
		if( $this->saveUploadedFile( $this->mUploadSaveName,
		                             $this->mUploadTempName,
		                             !empty( $this->mSessionKey ) ) ) {
			/**
			 * Update the upload log and create the description page
			 * if it's a new file.
			 */
			$img = Image::newFromName( $this->mUploadSaveName );
			$success = $img->recordUpload( $this->mUploadOldVersion,
			                                $this->mUploadDescription,
			                                $this->mUploadCopyStatus,
			                                $this->mUploadSource );

			if ( $success ) {
				$this->showSuccess();
			} else {
				// Image::recordUpload() fails if the image went missing, which is 
				// unlikely, hence the lack of a specialised message
				$wgOut->fileNotFoundError( $this->mUploadSaveName );
			}
		}
	}

	/**
	 * Move the uploaded file from its temporary location to the final
	 * destination. If a previous version of the file exists, move
	 * it into the archive subdirectory.
	 *
	 * @todo If the later save fails, we may have disappeared the original file.
	 *
	 * @param string $saveName
	 * @param string $tempName full path to the temporary file
	 * @param bool $useRename if true, doesn't check that the source file
	 *                        is a PHP-managed upload temporary
	 */
	function saveUploadedFile( $saveName, $tempName, $useRename = false ) {
		global $wgUploadDirectory, $wgOut;

		$fname= "SpecialUpload::saveUploadedFile";
		
		$dest = wfImageDir( $saveName );
		$archive = wfImageArchiveDir( $saveName );
		$this->mSavedFile = "{$dest}/{$saveName}";

		if( is_file( $this->mSavedFile ) ) {
			$this->mUploadOldVersion = gmdate( 'YmdHis' ) . "!{$saveName}";
			wfSuppressWarnings();
			$success = rename( $this->mSavedFile, "${archive}/{$this->mUploadOldVersion}" );
			wfRestoreWarnings();

			if( ! $success ) { 
				$wgOut->fileRenameError( $this->mSavedFile,
				  "${archive}/{$this->mUploadOldVersion}" );
				return false;
			}
			else wfDebug("$fname: moved file ".$this->mSavedFile." to ${archive}/{$this->mUploadOldVersion}\n");
		} 
		else {
			$this->mUploadOldVersion = '';
		}
		
		if( $useRename ) {
			wfSuppressWarnings();
			$success = rename( $tempName, $this->mSavedFile );
			wfRestoreWarnings();

			if( ! $success ) {
				$wgOut->fileCopyError( $tempName, $this->mSavedFile );
				return false;
			} else {
				wfDebug("$fname: wrote tempfile $tempName to ".$this->mSavedFile."\n");
			}
		} else {
			wfSuppressWarnings();
			$success = move_uploaded_file( $tempName, $this->mSavedFile );
			wfRestoreWarnings();

			if( ! $success ) {
				$wgOut->fileCopyError( $tempName, $this->mSavedFile );
				return false;
			}
			else wfDebug("$fname: wrote tempfile $tempName to ".$this->mSavedFile."\n");
		}
		
		chmod( $this->mSavedFile, 0644 );
		return true;
	}

	/**
	 * Stash a file in a temporary directory for later processing
	 * after the user has confirmed it.
	 *
	 * If the user doesn't explicitly cancel or accept, these files
	 * can accumulate in the temp directory.
	 *
	 * @param string $saveName - the destination filename
	 * @param string $tempName - the source temporary file to save
	 * @return string - full path the stashed file, or false on failure
	 * @access private
	 */
	function saveTempUploadedFile( $saveName, $tempName ) {
		global $wgOut;		
		$archive = wfImageArchiveDir( $saveName, 'temp' );
		$stash = $archive . '/' . gmdate( "YmdHis" ) . '!' . $saveName;

		if ( !move_uploaded_file( $tempName, $stash ) ) {
			$wgOut->fileCopyError( $tempName, $stash );
			return false;
		}
		
		return $stash;
	}
	
	/**
	 * Stash a file in a temporary directory for later processing,
	 * and save the necessary descriptive info into the session.
	 * Returns a key value which will be passed through a form
	 * to pick up the path info on a later invocation.
	 *
	 * @return int
	 * @access private
	 */
	function stashSession() {		
		$stash = $this->saveTempUploadedFile(
			$this->mUploadSaveName, $this->mUploadTempName );

		if( !$stash ) {
			# Couldn't save the file.
			return false;
		}
		
		$key = mt_rand( 0, 0x7fffffff );
		$_SESSION['wsUploadData'][$key] = array(
			'mUploadTempName' => $stash,
			'mUploadSize'     => $this->mUploadSize,
			'mOname'          => $this->mOname );
		return $key;
	}

	/**
	 * Remove a temporarily kept file stashed by saveTempUploadedFile().
	 * @access private
	 */
	function unsaveUploadedFile() {
		wfSuppressWarnings();
		$success = unlink( $this->mUploadTempName );
		wfRestoreWarnings();
		if ( ! $success ) {
			$wgOut->fileDeleteError( $this->mUploadTempName );
		}
	}

	/* -------------------------------------------------------------- */

	/**
	 * Show some text and linkage on successful upload.
	 * @access private
	 */
	function showSuccess() {
		global $wgUser, $wgOut, $wgContLang;
		
		$sk = $wgUser->getSkin();
		$ilink = $sk->makeMediaLink( $this->mUploadSaveName, Image::imageUrl( $this->mUploadSaveName ) );
		$dname = $wgContLang->getNsText( NS_IMAGE ) . ':'.$this->mUploadSaveName;
		$dlink = $sk->makeKnownLink( $dname, $dname );

		$wgOut->addHTML( '<h2>' . wfMsg( 'successfulupload' ) . "</h2>\n" );
		$text = wfMsg( 'fileuploaded', $ilink, $dlink );
		$wgOut->addHTML( '<p>'.$text."\n" );
		$wgOut->returnToMain( false );
	}

	/**
	 * @param string $error as HTML
	 * @access private
	 */
	function uploadError( $error ) {
		global $wgOut;
		$sub = wfMsg( 'uploadwarning' );
		$wgOut->addHTML( "<h2>{$sub}</h2>\n" );
		$wgOut->addHTML( "<h4 class='error'>{$error}</h4>\n" );
	}

	/**
	 * There's something wrong with this file, not enough to reject it
	 * totally but we require manual intervention to save it for real.
	 * Stash it away, then present a form asking to confirm or cancel.
	 *
	 * @param string $warning as HTML
	 * @access private
	 */
	function uploadWarning( $warning ) {
		global $wgOut, $wgUser, $wgLang, $wgUploadDirectory, $wgRequest;
		global $wgUseCopyrightUpload;

		$this->mSessionKey = $this->stashSession();
		if( !$this->mSessionKey ) {
			# Couldn't save file; an error has been displayed so let's go.
			return;
		}

		$sub = wfMsg( 'uploadwarning' );
		$wgOut->addHTML( "<h2>{$sub}</h2>\n" );
		$wgOut->addHTML( "<ul class='warning'>{$warning}</ul><br />\n" );

		$save = wfMsg( 'savefile' );
		$reupload = wfMsg( 'reupload' );
		$iw = wfMsg( 'ignorewarning' );
		$reup = wfMsg( 'reuploaddesc' );
		$titleObj = Title::makeTitle( NS_SPECIAL, 'Upload' );
		$action = $titleObj->escapeLocalURL( 'action=submit' );

		if ( $wgUseCopyrightUpload )
		{
			$copyright =  "
	<input type='hidden' name='wpUploadCopyStatus' value=\"" . htmlspecialchars( $this->mUploadCopyStatus ) . "\" />
	<input type='hidden' name='wpUploadSource' value=\"" . htmlspecialchars( $this->mUploadSource ) . "\" />
	";
		} else {
			$copyright = "";
		}

		$wgOut->addHTML( "
	<form id='uploadwarning' method='post' enctype='multipart/form-data' action='$action'>
		<input type='hidden' name='wpUploadAffirm' value='1' />
		<input type='hidden' name='wpIgnoreWarning' value='1' />
		<input type='hidden' name='wpSessionKey' value=\"" . htmlspecialchars( $this->mSessionKey ) . "\" />
		<input type='hidden' name='wpUploadDescription' value=\"" . htmlspecialchars( $this->mUploadDescription ) . "\" />
		<input type='hidden' name='wpDestFile' value=\"" . htmlspecialchars( $this->mDestFile ) . "\" />
	{$copyright}
	<table border='0'>
		<tr>
			<tr>
				<td align='right'>
					<input tabindex='2' type='submit' name='wpUpload' value='$save' />
				</td>
				<td align='left'>$iw</td>
			</tr>
			<tr>
				<td align='right'>
					<input tabindex='2' type='submit' name='wpReUpload' value='{$reupload}' />
				</td>
				<td align='left'>$reup</td>
			</tr>
		</tr>
	</table></form>\n" );
	}

	/**
	 * Displays the main upload form, optionally with a highlighted
	 * error message up at the top.
	 *
	 * @param string $msg as HTML
	 * @access private
	 */
	function mainUploadForm( $msg='' ) {
		global $wgOut, $wgUser, $wgLang, $wgUploadDirectory, $wgRequest;
		global $wgUseCopyrightUpload;
		
		$cols = intval($wgUser->getOption( 'cols' ));
		$ew = $wgUser->getOption( 'editwidth' );
		if ( $ew ) $ew = " style=\"width:100%\"";
		else $ew = '';

		if ( '' != $msg ) {
			$sub = wfMsg( 'uploaderror' );
			$wgOut->addHTML( "<h2>{$sub}</h2>\n" .
			  "<h4 class='error'>{$msg}</h4>\n" );
		}
		$wgOut->addWikiText( wfMsg( 'uploadtext' ) );
		$sk = $wgUser->getSkin();


		$sourcefilename = wfMsg( 'sourcefilename' );
		$destfilename = wfMsg( 'destfilename' );
		
		$fd = wfMsg( 'filedesc' );
		$ulb = wfMsg( 'uploadbtn' );

		$clink = $sk->makeKnownLink( wfMsgForContent( 'copyrightpage' ),
		  wfMsg( 'copyrightpagename' ) );
		$ca = wfMsg( 'affirmation', $clink );
		$iw = wfMsg( 'ignorewarning' );

		$titleObj = Title::makeTitle( NS_SPECIAL, 'Upload' );
		$action = $titleObj->escapeLocalURL();

		$encDestFile = htmlspecialchars( $this->mDestFile );

		$source = "
	<td align='right'>
	<input tabindex='3' type='checkbox' name='wpUploadAffirm' value='1' id='wpUploadAffirm' />
	</td><td align='left'><label for='wpUploadAffirm'>{$ca}</label></td>
	" ;
		if ( $wgUseCopyrightUpload )
		  {
			$source = "
	<td align='right' nowrap='nowrap'>" . wfMsg ( 'filestatus' ) . ":</td>
	<td><input tabindex='3' type='text' name=\"wpUploadCopyStatus\" value=\"" .
	htmlspecialchars($this->mUploadCopyStatus). "\" size='40' /></td>
	</tr><tr>
	<td align='right'>". wfMsg ( 'filesource' ) . ":</td>
	<td><input tabindex='4' type='text' name='wpUploadSource' value=\"" .
	htmlspecialchars($this->mUploadSource). "\" size='40' /></td>
	" ;
		  }

		$wgOut->addHTML( "
	<form id='upload' method='post' enctype='multipart/form-data' action=\"$action\">
	<table border='0'><tr>

	<td align='right'>{$sourcefilename}:</td><td align='left'>
	<input tabindex='1' type='file' name='wpUploadFile' id='wpUploadFile' onchange='fillDestFilename()' size='40' />
	</td></tr><tr>

	<td align='right'>{$destfilename}:</td><td align='left'>
	<input tabindex='1' type='text' name='wpDestFile' id='wpDestFile' size='40' value=\"$encDestFile\" />
	</td></tr><tr>
	
	<td align='right'>{$fd}:</td><td align='left'>
	<textarea tabindex='2' name='wpUploadDescription' rows='6' cols='{$cols}'{$ew}>"	
	  . htmlspecialchars( $this->mUploadDescription ) .
	"</textarea>
	</td></tr><tr>
	{$source}
	</tr>
	<tr><td></td><td align='left'>
	<input tabindex='5' type='submit' name='wpUpload' value=\"{$ulb}\" />
	</td></tr></table></form>\n" );
	}
	
	/* -------------------------------------------------------------- */

	/**
	 * Split a file into a base name and all dot-delimited 'extensions'
	 * on the end. Some web server configurations will fall back to
	 * earlier pseudo-'extensions' to determine type and execute
	 * scripts, so the blacklist needs to check them all.
	 *
	 * @return array
	 */
	function splitExtensions( $filename ) {
		$bits = explode( '.', $filename );
		$basename = array_shift( $bits );
		return array( $basename, $bits );
	}
	
	/**
	 * Perform case-insensitive match against a list of file extensions.
	 * Returns true if the extension is in the list.
	 *
	 * @param string $ext
	 * @param array $list
	 * @return bool
	 */
	function checkFileExtension( $ext, $list ) {
		return in_array( strtolower( $ext ), $list );
	}

	/**
	 * Perform case-insensitive match against a list of file extensions.
	 * Returns true if any of the extensions are in the list.
	 *
	 * @param array $ext
	 * @param array $list
	 * @return bool
	 */
	function checkFileExtensionList( $ext, $list ) {
		foreach( $ext as $e ) {
			if( in_array( strtolower( $e ), $list ) ) {
				return true;
			}
		}
		return false;
	}
	
	/**
	 * Verifies that it's ok to include the uploaded file
	 *
	 * @param string $tmpfile the full path opf the temporary file to verify
	 * @param string $extension The filename extension that the file is to be served with
	 * @return mixed true of the file is verified, a WikiError object otherwise.
	 */
	function verify( $tmpfile, $extension ) {
		#magically determine mime type
		$magic=& wfGetMimeMagic();
		$mime= $magic->guessMimeType($tmpfile,false);
		
		$fname= "SpecialUpload::verify";
		
		#check mime type, if desired
		global $wgVerifyMimeType;
		if ($wgVerifyMimeType) {

			#check mime type against file extension
			if( !$this->verifyExtension( $mime, $extension ) ) {
				return new WikiErrorMsg( 'uploadcorrupt' );
			}
		
			#check mime type blacklist
			global $wgMimeTypeBlacklist;
			if( isset($wgMimeTypeBlacklist) && !is_null($wgMimeTypeBlacklist) 
				&& $this->checkFileExtension( $mime, $wgMimeTypeBlacklist ) ) {
				return new WikiErrorMsg( 'badfiletype', htmlspecialchars( $mime ) );
			}
		}
	
		#check for htmlish code and javascript
		if( $this->detectScript ( $tmpfile, $mime ) ) {
			return new WikiErrorMsg( 'uploadscripted' );
		}
		
		/**
		* Scan the uploaded file for viruses
		*/
		$virus= $this->detectVirus($tmpfile);
		if ( $virus ) {
			return new WikiErrorMsg( 'uploadvirus', htmlspecialchars($virus) );
		}
		
		wfDebug( "$fname: all clear; passing.\n" );
		return true;
	}
	
	/**
	 * Checks if the mime type of the uploaded file matches the file extension.
	 *
	 * @param string $mime the mime type of the uploaded file
	 * @param string $extension The filename extension that the file is to be served with
	 * @return bool
	 */
	function verifyExtension( $mime, $extension ) {
		$fname = 'SpecialUpload::verifyExtension';
		
		if (!$mime || $mime=="unknown" || $mime=="unknown/unknown") {
			wfDebug( "$fname: passing file with unknown mime type\n" );
			return true; 
		}
		
		$magic=& wfGetMimeMagic();
		
		$match= $magic->isMatchingExtension($extension,$mime);
		
		if ($match===NULL) {
			wfDebug( "$fname: no file extension known for mime type $mime, passing file\n" );
			return true; 
		} elseif ($match===true) {
			wfDebug( "$fname: mime type $mime matches extension $extension, passing file\n" );
			
			#TODO: if it's a bitmap, make sure PHP or ImageMagic resp. can handle it!
			return true;
			
		} else {
			wfDebug( "$fname: mime type $mime mismatches file extension $extension, rejecting file\n" );
			return false; 
		}
	}
	
	/** Heuristig for detecting files that *could* contain JavaScript instructions or 
	* things that may look like HTML to a browser and are thus
	* potentially harmful. The present implementation will produce false positives in some situations.
	*
	* @param string $file Pathname to the temporary upload file
	* @param string $mime The mime type of the file
	* @return bool true if the file contains something looking like embedded scripts
	*/
	function detectScript($file,$mime) {
		
		#ugly hack: for text files, always look at the entire file.
		#For binarie field, just check the first K.
		
		if (strpos($mime,'text/')===0) $chunk = file_get_contents( $file );
		else {
			$fp = fopen( $file, 'rb' );
			$chunk = fread( $fp, 1024 );
			fclose( $fp );
		}
		
		$chunk= strtolower( $chunk );
		
		if (!$chunk) return false;
		
		#decode from UTF-16 if needed (could be used for obfuscation).
		if (substr($chunk,0,2)=="\xfe\xff") $enc= "UTF-16BE"; 
		elseif (substr($chunk,0,2)=="\xff\xfe") $enc= "UTF-16LE"; 
		else $enc= NULL;
			
		if ($enc) $chunk= iconv($enc,"ASCII//IGNORE",$chunk);
		
		$chunk= trim($chunk);
		
		#FIXME: convert from UTF-16 if necessarry!
		
		wfDebug("SpecialUpload::detectScript: checking for embedded scripts and HTML stuff\n");
		
		#check for HTML doctype
		if (eregi("<!DOCTYPE *X?HTML",$chunk)) return true;
		
		/**
		* Internet Explorer for Windows performs some really stupid file type
		* autodetection which can cause it to interpret valid image files as HTML
		* and potentially execute JavaScript, creating a cross-site scripting
		* attack vectors.
		*
		* Apple's Safari browser also performs some unsafe file type autodetection
		* which can cause legitimate files to be interpreted as HTML if the
		* web server is not correctly configured to send the right content-type
		* (or if you're really uploading plain text and octet streams!)
		*
		* Returns true if IE is likely to mistake the given file for HTML.
		* Also returns true if Safari would mistake the given file for HTML
		* when served with a generic content-type.
		*/
		
		$tags = array(
			'<body',
			'<head',
			'<html',   #also in safari
			'<img',
			'<pre',
			'<script', #also in safari
			'<table',
			'<title'   #also in safari
			);
			
		foreach( $tags as $tag ) {
			if( false !== strpos( $chunk, $tag ) ) {
				return true;
			}
		}
		
		/*
		* look for javascript 
		*/
		
		#resolve entity-refs to look at attributes. may be harsh on big files... cache result?
		$chunk = Sanitizer::decodeCharReferences( $chunk );
		
		#look for script-types
		if (preg_match("!type\s*=\s*['\"]?\s*(\w*/)?(ecma|java)!sim",$chunk)) return true;
		
		#look for html-style script-urls
		if (preg_match("!(href|src|data)\s*=\s*['\"]?\s*(ecma|java)script:!sim",$chunk)) return true;
		
		#look for css-style script-urls
		if (preg_match("!url\s*\(\s*['\"]?\s*(ecma|java)script:!sim",$chunk)) return true;
		
		wfDebug("SpecialUpload::detectScript: no scripts found\n");
		return false;
	}
	
	/** Generic wrapper function for a virus scanner program.
	* This relies on the $wgAntivirus and $wgAntivirusSetup variables.
	* $wgAntivirusRequired may be used to deny upload if the scan fails.
	*
	* @param string $file Pathname to the temporary upload file
	* @return mixed false if not virus is found, NULL if the scan fails or is disabled,
	*         or a string containing feedback from the virus scanner if a virus was found.
	*         If textual feedback is missing but a virus was found, this function returns true.
	*/
	function detectVirus($file) {
		global $wgAntivirus, $wgAntivirusSetup, $wgAntivirusRequired;
		
		$fname= "SpecialUpload::detectVirus";
		
		if (!$wgAntivirus) { #disabled?
			wfDebug("$fname: virus scanner disabled\n");
			
			return NULL;
		}
		
		if (!$wgAntivirusSetup[$wgAntivirus]) { 
			wfDebug("$fname: unknown virus scanner: $wgAntivirus\n"); 

			$wgOut->addHTML( "<div class='error'>Bad configuration: unknown virus scanner: <i>$wgAntivirus</i></div>\n" ); #LOCALIZE
			
			return "unknown antivirus: $wgAntivirus";
		}
		
		#look up scanner configuration
		$virus_scanner= $wgAntivirusSetup[$wgAntivirus]["command"]; #command pattern
		$virus_scanner_codes= $wgAntivirusSetup[$wgAntivirus]["codemap"]; #exit-code map
		$msg_pattern= $wgAntivirusSetup[$wgAntivirus]["messagepattern"]; #message pattern
		
		$scanner= $virus_scanner; #copy, so we can resolve the pattern
		
		if (strpos($scanner,"%f")===false) $scanner.= " ".wfEscapeShellArg($file); #simple pattern: append file to scan
		else $scanner= str_replace("%f",wfEscapeShellArg($file),$scanner); #complex pattern: replace "%f" with file to scan
		
		wfDebug("$fname: running virus scan: $scanner \n");
		
		#execute virus scanner
		$code= false;
		
		#NOTE: there's a 50 line workaround to make stderr redirection work on windows, too.
		#      that does not seem to be worth the pain. 
		#      Ask me (Duesentrieb) about it if it's ever needed.
		if (wfIsWindows()) exec("$scanner",$output,$code); 
		else exec("$scanner 2>&1",$output,$code); 
		
		$exit_code= $code; #remeber for user feedback
		
		if ($virus_scanner_codes) { #map exit code to AV_xxx constants.
			if (isset($virus_scanner_codes[$code])) $code= $virus_scanner_codes[$code]; #explicite mapping
			else if (isset($virus_scanner_codes["*"])) $code= $virus_scanner_codes["*"]; #fallback mapping
		}
		
		if ($code===AV_SCAN_FAILED) { #scan failed (code was mapped to false by $virus_scanner_codes)
			wfDebug("$fname: failed to scan $file (code $exit_code).\n");
			
			if ($wgAntivirusRequired) return "scan failed (code $exit_code)";
			else return NULL; 
		}
		else if ($code===AV_SCAN_ABORTED) { #scan failed because filetype is unknown (probably imune)
			wfDebug("$fname: unsupported file type $file (code $exit_code).\n");
			return NULL; 
		}
		else if ($code===AV_NO_VIRUS) {
			wfDebug("$fname: file passed virus scan.\n");
			return false; #no virus found
		}
		else { 
			$output= join("\n",$output);
			$output= trim($output);
			
			if (!$output) $output= true; #if ther's no output, return true
			else if ($msg_pattern) {
				$groups= array();
				if (preg_match($msg_pattern,$output,$groups)) {
					if ($groups[1]) $output= $groups[1];
				}
			}
			
			wfDebug("$fname: FOUND VIRUS! scanner feedback: $output");
			return $output;
		}
	}
	
	
}
?>
