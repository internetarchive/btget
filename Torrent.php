<?php
require_once('Module.inc');

class Torrent extends Module
{
  public function version()
  { return '$Revision: 00000 $ $Date: 2011-01-01 00:00:00 +0000 (Tue, 01 Jan 2011) $'; }

  public function derive()
  {
    // REQUIRES:
    //  transmission-daemon   current configuration: listening on default port 9091 for rpc from transmission-remote CLI
    //  transmission-remote   
    
    // INPUTS:
    //   foo.torrent
    // OUTPUTS:
    //   foo_torrent.tlg        comma delimited manifest of files in .torrent:  orig filename,sanitzed filename,size in bytes 
    //   <torrent contents>     note that filenames are changed to Archive-safe versions (no UTF support currently!)
        
    // NOTES:
    //   currently uses one daemon, we will move to one daemon/session
    //   currently assumes one torrent per item, as with scanned books (but what about .torrents full of .torrents?)

    // DEBUG
    // Util::cmdPP('echo | whereis transmission-remote');
    // Util::cmdPP('echo | whereis transmission-daemon');
    
    $btget = configGetPetaboxPath('bin-btget');
    
    // give transmission-daemon write permission, it runs under debian-transmission by default
    // when we are starting/stopping daemon for each session this may no longer be necessary
    Util::cmd('chmod ugo+w '.Util::esc($this->tmp), 'PRINT');    
     
    // Make sure settings are current on the local daemon. settings.json symlinked from /petabox/etc/transmisison-daemon
    // TODO: start daemon here with a specific RPC port and peer port
    Util::cmdPP('/etc/init.d/transmission-daemon reload');

    // Util::cmdPP(Util::esc($_SERVER['PETABOX_HOME']).'/sw/bin/btget.py -stdout -sanitize -verbose '.Util::esc($this->sourceFile).' -dir='.Util::esc($this->tmp) );
    Util::cmdPP("$btget -stdout -sanitize -verbose ".Util::esc($this->sourceFile)." -dir=".Util::esc($this->tmp) );   
    
    // in addition to the manifest, btget generates a disposable helper file the contents of which are:
    //  (a) the SHA1 infohash fingerprint for the torrent; and 
    //  (b) the torrent name field from its bencoded info, which == the directory name created by transmission-daemon
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
    // while moving, eliminate the intermediate dir $torrentName
    // NOTE: $torrentName is not a sanized filename, but we fail on retrieval if it appears malicious
    $contentPath = $this->tmp.$torrentName;
    if (is_dir($contentPath) ) {
        Util::cmd('cp -r '.Util::esc($contentPath).'/* '.Util::esc($this->itemDir), 'PRINT');    
        Util::cmd('rm -rf '.Util::esc($contentPath), 'PRINT');
    } else {
        // single-file case 
        Util::cmd('mv '.Util::esc($contentPath).' '.Util::esc($this->itemDir), 'PRINT');    
    }    
    
    // move the generated manifest file which is our targetFile
    $targetContentsFile = $this->tmp.$this->shortName($this->targetFile);
    Util::cmd('mv '.Util::esc($targetContentsFile).' '.Util::esc($this->itemDir), 'PRINT');    

    // move the log file if there is one
    $targetLogFile = $this->tmp.$this->shortName($this->sourceFile).'.log';        
//    if (file_exists($targetLogFile)) {
//        Util::cmd('mv '.Util::esc($targetLogFile).' '.Util::esc($this->itemDir), 'PRINT');    
//    }

    // update _meta.xml 
    // TODO: use ModifyXML per Hank's guidance to support multiple .torrents per item; get Alexis' sign off on tags
//    ModifyXML::updateElem('source', $this->shortName($this->sourceFile),
//                          "{$this->identifier}_meta.xml", $this->tmp);        
    ModifyXML::updateElem('external-identifier', "torrent:urn:sha1:".$torrentHash,
                          "{$this->identifier}_meta.xml", $this->tmp);        
//    ModifyXML::updateElem('identifier-torrent-name', "torrent:filename:".$torrentName,
//                          "{$this->identifier}_meta.xml", $this->tmp);        
                          
    // target manifest file is line-delimited information for each file in the torrent, in the format
    //  originalname,sanitizedname,length
    
    if (file_exists($this->targetFile)) {
        $fg = new FormatGetter;
        $lines = file($this->targetFile);
        foreach ($lines as $line) {
            $originalFile = strtok($line, ',');
            $outFile = strtok(',');
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
        // manifest file missing
        echo "MISSING TARGET FILE: $manifest\n";
        fatal('Target manifest for torrent missing: '.Util::esc($manifest));
    }

  }

}
?>
