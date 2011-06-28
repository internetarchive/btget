<?php
require_once('Module.inc');

class Torrent extends Module
{
  public function version()
  { return '$Revision: 00000 $ $Date: 2011-01-01 00:00:00 +0000 (Tue, 01 Jan 2011) $'; }

  public function derive()
  {
    // the following class variables (among others - see Module.inc) will already be set
    // when this function gets called:
    // $this->identifier   the item ID
    // $this->itemDir      full path to item dir, with trailing /

    // $this->sourceFile   full path to source file (*.torrent, in this case); may be in subdir of itemdir
    // $this->sourceFormat source format name (as given in <dependency ... variation="{name}" /> in derivations.xml)

    // $this->targetFile   likewise for the target file
    // $this->targetFormat (as given in <variation name="{name}" ... /> in derivations.xml)

    // $this->tmp;         preexisting empty tmp dir (with trailing /) for any files you need
    //                     to create (will later be rm'd automatically)

    // REQUIRES:
    //  transmission-daemon   configuration: listening on default port 9091 for rpc from transmission-remote CLI
    //  transmission-remote   
    
    // OUTPUTS:
    //   .torrentcontents   comma delimited manifest of files in .torrent:  filename,size in bytes 
    //   torrent contents 
    
    // TODO:
    //   check for non-conforming filenames (e.g. illegal characters leading to exploits)
    
    // NOTES:
    //   currently assumes one torrent per item, as with scanned books (but what about .torrents full of .torrents?)
    //   currently using transmission-daemon's whitelist for IP ranges from which it accepts control
    //   currently moving .torrent.log file containing output of btget, could discard, or is it of historic interest?
    
    // transmission-daemon needs write permission, it runs under debian-transmission by default :P
    Util::cmd('chmod ugo+w '.Util::esc($this->tmp), 'PRINT');    
    
    // DEBUG
    Util::cmdPP('echo | whereis transmission-remote'); // last check still not installed on workers, so running out of /home/ximmm :P
    // Util::cmdPP('echo | whereis transmission-daemon');
    // push out the revised permissions in /home/ximm/petabox/etc/transmission-daemon/settings.json 
    // Util::cmd('sudo cp /etc/transmission-daemon/settings.json /home/ximm/projects/bitty/bitlogs/default_settings.json', 'PRINT');
    
    // This is now hopefully unnecessary since Raj is pushing into /petabox/etc/transmission-daemon/settings.json to all workers
    // Util::cmdPP('sudo cp /home/ximm/projects/bitty/bitlogs/settings.json /etc/transmission-daemon/settings.json');
    Util::cmdPP('sudo /etc/init.d/transmission-daemon reload');

    // TODO: move script into petabox bin basket 
    Util::cmd('/home/ximm/projects/bitty/btget -stdout -log=/home/ximm/projects/bitty/bitlogs/btgettest.log '.Util::esc($this->sourceFile).' -dir='.Util::esc($this->tmp), 'PRINT');   

    // seize ownership of downloaded files; may not be necessary in worker environment, not sure what user 
    //  derive.php is executed under
    // Util::cmd('sudo chown -R ximm '.Util::esc($this->tmp), 'PRINT');        
    Util::cmd('sudo chmod ugo+w '.Util::esc($this->tmp).'*', 'PRINT');
    
    // in addition to the manifest, I generate a disposable helper file including:
    //  (a) the SHA1 infohash fingerprint for the torrent; and 
    //  (b) the torrent name field from its bencoded info, which == the directory name created by transmission-daemon
    $targetHashFile = $this->tmp.$this->shortName($this->sourceFile).'hash';        
    if (file_exists($targetHashFile)) {
        $lines = file($targetHashFile);
        $torrentHash = strtok($lines[0], "\n");
        $torrentName = strtok($lines[1], "\n");
    } else {
        Util::cmdPP('echo MISSING HASH FILE:'. Util::esc($targetHashFile) );
    }
    
    // torrent contents are in $this->tmp/$torrentName/... while moving eliminate the intermediate dir $torrentName
    $contentPath = $this->tmp.$torrentName;    
    Util::cmd('cp -r '.Util::esc($contentPath).'/* '.Util::esc($this->itemDir), 'PRINT');    
    Util::cmd('rm -rf '.Util::esc($contentPath), 'PRINT');    
    
    // move the generated manifest file which is our targetFile
    $targetContentsFile = $this->tmp.$this->shortName($this->targetFile);
    Util::cmd('mv '.Util::esc($targetContentsFile).' '.Util::esc($this->itemDir), 'PRINT');    

    // update _meta.xml 
    ModifyXML::updateElem('source', $this->shortName($this->sourceFile),
                          "{$this->identifier}_meta.xml", $this->tmp);        
    ModifyXML::updateElem('identifier-torrent-infohash', $torrentHash,
                          "{$this->identifier}_meta.xml", $this->tmp);        
    ModifyXML::updateElem('identifier-torrent-name', $torrentName,
                          "{$this->identifier}_meta.xml", $this->tmp);        
                          
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
                Util::cmdPP('echo MISSING FILE FROM TORRENT:' . Util::esc($outAbsPath) );
            }
        }
    } else {  
        // manifest file missing
        Util::cmdPP('echo MISSING TARGET FILE: '.Util::esc($manifest) );
        fatal('Target manifest for torrent missing: '.Util::esc($manifest));
    }

  }

}
?>
