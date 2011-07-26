<?php
require_once('Module.inc');

class Torrent extends Module
{
  public function version()
  { return '$Revision: 37287 $ $Date: 2011-07-26 01:13:27 +0000 (Tue, 26 Jul 2011) $'; }

  public function derive()
  {
    // REQUIRES:1
    //  transmission-daemon     started and killed as subprocess of btget.py
    //  transmission-remote   
    
    // INPUTS:
    //   foo.torrent
    // OUTPUTS:
    //   foo_torrent.txt        comma delimited manifest of files in .torrent:  sanitized filename,size in bytes,orig filename 
    //   <torrent contents>     filenames are sanitized to be Archive-safe (no spaces, no odd punctuation, etc.)
        
    // NOTES:
    //   currently uses one daemon per session
    //   currently presumes (but does not require) one torrent per item

    // DEBUG
    // Util::cmdPP('echo | whereis transmission-remote');
    // Util::cmdPP('echo | whereis transmission-daemon');
    
    $btget = configGetPetaboxPath('bin-btget');
    
    // give transmission-daemon write permission, it runs under debian-transmission by default
    // since daemon now runs under our program group this may no longer be necessary
    Util::cmd('chmod ugo+w '.Util::esc($this->tmp), 'PRINT');    

    // get the torrent if we can, timing out after ten days (btget exits with status 1 after one week currently)     
    Util::cmdPP("timeout 864000 $btget -stdout -sanitize -verbose ".Util::esc($this->sourceFile)." -dir=".Util::esc($this->tmp) );   
    
    // in addition to the manifest, btget generates a disposable helper file the contents of which are:
    //  (a) the SHA1 infohash fingerprint for the torrent; and 
    //  (b) the torrent name field extracted from its bencoded info, which == the directory name created by transmission-daemon
    $targetHashFile = $this->tmp.$this->shortName($this->sourceFile).'hash';        
    if (file_exists($targetHashFile)) {
        $lines = file($targetHashFile);
        $torrentHash = strtok($lines[0], "\n");
        $torrentName = strtok($lines[1], "\n");
    } else {
        echo "MISSING HASH FILE: $targetHashFile\n";
    }
    
    // torrent contents are automatically stored by transmission-d in $this->tmp/$torrentName/...
    // this path is a subdirectory for multi-file torrents, but a single file only for single-file torrents
    // while moving to itemDir, eliminate the intermediate dir $torrentName
    // NOTE: $torrentName is not sanitized, but btgets aborts if the name implies a path that appears malicious
    $contentPath = $this->tmp.$torrentName;
    if (is_dir($contentPath) ) {
        Util::cmd('cp -r '.Util::esc($contentPath).'/* '.Util::esc($this->itemDir), 'PRINT');    
        Util::cmd('rm -rf '.Util::esc($contentPath), 'PRINT');
    } else {
        // single-file case 
        Util::cmd('mv '.Util::esc($contentPath).' '.Util::esc($this->itemDir), 'PRINT');    
    }    
    
    // move the manifest file which is our targetFile
    $targetContentsFile = $this->tmp.$this->shortName($this->targetFile);
    Util::cmd('mv '.Util::esc($targetContentsFile).' '.Util::esc($this->itemDir), 'PRINT');    

    // Useful for debugging: by default a log file is dumped in the dir passed to btget
    // As long as the -stdout option is used with btget and cmdPP used to capture output in
    //  task logs, however, this is redundant.
    // move the log file if there is one
//    $targetLogFile = $this->tmp.$this->shortName($this->sourceFile).'.log';        
//    if (file_exists($targetLogFile)) {
//        Util::cmd('mv '.Util::esc($targetLogFile).' '.Util::esc($this->itemDir), 'PRINT');    
//    }

    // update _meta.xml 
    // potential weakness: assumes there are not 99 torrents in this item -- but then ideally there should be only ONE
    $metaXmlFile = "{$this->itemDir}{$this->identifier}_meta.xml"; 
    $changes['source[99]'] = "torrent:urn:sha1:".$torrentHash; 
    $mxml = ModifyXML::modify($metaXmlFile, $changes);
    ModifyXML::safeWrite($mxml, $metaXmlFile, $this->identifier, $this->tmp);    
 
    // target manifest file is line-delimited information for each file in the torrent, in the format
    //  sanitizedname,length,originalname
    // sanitized name is first as original name is not guaranteed to omit any delimiter, quoting, or escaping we might use
    // an alternative would be to crawl target dir for new files, skipping those we generated...
    if (file_exists($this->targetFile)) {
        $fg = new FormatGetter;
        $lines = file($this->targetFile);
        foreach ($lines as $line) {
            $outFile = strtok($line, ',');
            $outAbsPath = $this->itemDir.$outFile;
            if (file_exists($outAbsPath)) {
                $formatName = $fg->pickFormat($outFile);
                $this->extraTarget($outFile, $formatName);
            } else {
                // file in .torrent missing from download directory
                echo "MISSING FILE FROM TORRENT: $outAbsPath\n";
            }
        }
    } else {  
        // manifest file missing (btget must have failed)
        echo "MISSING TARGET FILE: $manifest\n";
        fatal('Target manifest for torrent missing: '.Util::esc($manifest));
    }

  }

}
?>
