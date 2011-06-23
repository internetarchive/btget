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
    //  transmission-daemon   configuration: listening on default port 9091 for rpc from transmission-remote
    //  transmission-remote   
    
    // OUTPUTS:
    //   .torrentcontents   comma delimited manifest of files in .torrent:  filename,size in bytes 
    //   .torrenthash       one-line file containing the infohash of the .torrent
    
    // TODO:
    //   check for abusive filenames (containing illegal characters to prevent exploits)
    
    // NOTES:
    //   currently using transmission-daemon's whitelist for IP ranges from which it accepts control
    //   currently moving .torrent.log file containing output of btget, could discard, or is it of historic interest?
    
    Util::cmd('./btget -stdout '.Util::esc($outFile).' -dir='.Util::esc($this->tmp), 'PRINT');    
    Util::cmd('mv '.Util::esc(($this->tmp).'* '.Util::esc($this->itemDir).'.', 'PRINT');    
    // Assume this is unnecessary: 
    // Util::cmd('mv '.Util::esc(($this->sourceFile).'* '.Util::esc($this->itemDir).'.', 'PRINT');
        
    $manifest =  $this->sourceFile . "contents" ;
    if (file_exists($outFile)) {
        $man_handle = fopen($manifest, "rb");
        while (!feof($file_handle) ) {
            $line_of_text = fgets($man_handle);
            $parts = explode(",", $line_of_text);
            $outFile = $parts[0];               
            // Question: can FormatGetter handle a path if the torrent had a tree?
            // Currently the manifest includes the path, not just filenames
            if (file_exists($outFile)) {
                $fg = new FormatGetter;
                $formatName = $fg->pickFormat($outFile);
                $this->extraTarget($outFile, $formatName);
            } else {
                // file in .torrent missing from download directory
                // TODO: what's the preferred method to raise an exception here?
            }
        }        
        fclose($man_handle);    
    } else {  
        // manifest file missing
        // TODO: what's the preferred method to raise an exception here?
    }

  }

}
?>
