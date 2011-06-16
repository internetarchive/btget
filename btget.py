# TODO:
#  Lots more rtorrent management: checking up/down speeds, etc.
#  Check seeds for torrents; retry logic? How long do we keep at a torrent?
#  rtorrent session management: we need to be able to save/resume when machine goes down
#  torrent file name and content file names can be nonconfirming in all sort of terrible ways... strategy? :O
#  when torrent path consists of subdirectory, not sure 
import io
import os
import sys
import shutil
import string
import time
import httplib
from urlparse import urlparse 
import urllib2
import subprocess
import datetime
import codecs
import re
import bencode
import pprint
import urllib
import hashlib
import StringIO

try:
    import psyco # Optional, 2.5x improvement in speed
    psyco.full()
except ImportError:
    pass

decimal_match = re.compile('\d')

def bdecode(data):
    '''Main function to decode bencoded data'''
    chunks = list(data)
    chunks.reverse()
    root = _dechunk(chunks)
    return root

def _dechunk(chunks):
    item = chunks.pop()

    if item == 'd': 
        item = chunks.pop()
        hash = {}
        while item != 'e':
            chunks.append(item)
            key = _dechunk(chunks)
            hash[key] = _dechunk(chunks)
            item = chunks.pop()
        return hash
    elif item == 'l':
        item = chunks.pop()
        list = []
        while item != 'e':
            chunks.append(item)
            list.append(_dechunk(chunks))
            item = chunks.pop()
        return list
    elif item == 'i':
        item = chunks.pop()
        num = ''
        while item != 'e':
            num  += item
            item = chunks.pop()
        return int(num)
    elif decimal_match.search(item):
        num = ''
        while decimal_match.search(item):
            num += item
            item = chunks.pop()
        line = ''
        for i in range(int(num)):
            line += chunks.pop()
        return line

def pingTracker ( ahash ):
    # return len( ahash)
    #tracker = "http://inferno.demonoid.me:3393/"
    tracker = "http://10.rarbg.com/"
    surl = tracker + ("scrape?info_hash=%s" % ahash )
    curllist = ['curl', '-s', surl]
    p = subprocess.Popen( curllist, stderr = subprocess.STDOUT, stdout = subprocess.PIPE )
    (res, err) = p.communicate()
    exitcode = p.wait()
    if exitcode == 0:
        return res
    else:
        return ' - failed - '

def plingTracker ( hashlist):
    idx = 1
    for ahash in hashlist:
        rep = pingTracker( ahash )
        print( '[%s] %s : %s ' % ( str(idx).zfill(3), ahash, rep ) )
        idx = idx + 1


def ByteToHex( byteStr ):
    """
    Convert a byte string to it's hex string representation e.g. for output.
    """
    
    # Uses list comprehension which is a fractionally faster implementation than
    # the alternative, more readable, implementation below
    #   
    #    hex = []
    #    for aChar in byteStr:
    #        hex.append( "%02X " % ord( aChar ) )
    #
    #    return ''.join( hex ).strip()        

    return ''.join( [ "%02X" % ord( x ) for x in byteStr ] ).lower()

def HexToByte( hexStr ):
    """
    Convert a string hex byte values into a byte string. The Hex Byte values may
    or may not be space separated.
    """
    # The list comprehension implementation is fractionally slower in this case    
    #
    #    hexStr = ''.join( hexStr.split(" ") )
    #    return ''.join( ["%c" % chr( int ( hexStr[i:i+2],16 ) ) \
    #                                   for i in range(0, len( hexStr ), 2) ] )
 
    bytes = []

    hexStr = ''.join( hexStr.split(" ").upper() )

    for i in range(0, len(hexStr), 2):
        bytes.append( chr( int (hexStr[i:i+2], 16 ) ) )

    return ''.join( bytes )

# Logging   
            
def dlog ( lev, str ):
    global sout
    global dlogfile
    global dloglevel
    if lev <= dloglevel and dlogfile is not None:
        lt = datetime.datetime.now()
        try:
            dlogfile.write( "%s %s\n" %  ( lt,  str.encode( 'utf-8' ) ) )
        except:
            try:
                dlogfile.write ( "%s <* removed unprintable chars *> %s\n" % (lt, printable( str ) ) )
            except:
                dlogfile.write ( "%s <* unprintable *>\n" % lt )
        if sout is True:
            print str
            sys.stdout.flush()
        dlogfile.flush()
    return

def dlogAppend ( lev, str ):
    global sout
    global dlogfile
    global dloglevel
    if lev <= dloglevel and dlogfile is not None:
        try:
            dlogfile.write( "%s" %  ( str.encode( 'utf-8' ) ) )
        except:
            try:
                dlogfile.write ( "%s <* removed unprintable chars *> %s" % (lt, printable( str ) ) )
            except:
                dlogfile.write( "%s <* unprintable *>\n" % lt )
        if sout is True:
            print str,
            sys.stdout.flush()
        dlogfile.flush()
    return


def printable ( dirty ):
    # eliminate non-printable chars
    clean = "".join(i for i in dirty if ord(i) < 128)
#    clean = ''.join([char for char in dirty if isascii(char)])
#    return ''.join([char for char in clean if isprint(char)])
    return clean

def sanitizeString ( dirty ):
    # eliminate only tabs and newlines
    clean = string.replace( dirty, "\n", " ") 
    clean = string.replace( clean, "\r", " ") 
    clean = string.replace( clean, "\t", "    ") 
#    clean = ''.join(char for char in dirty if (char not in ["\n","\t","\r"]))
    return clean
    
def parseTorrent( torrentFile ):
    """Return a tuple with info_hash, files, and printable info for logging as a string ready for xmlrpc"""
    try: 
        with open( torrentFile, "rb") as f:
            metainfo = bdecode( f.read() )
                
        info = metainfo['info']

        fils = info['files']
        
        encodedInfo = bencode.bencode(info)
        infoHash = hashlib.sha1(encodedInfo).hexdigest().upper()
    
        tmpStream = StringIO.StringIO()        
    
        pieces = StringIO.StringIO(info['pieces'])
        hashes = info['pieces'] 
        numhashes = len(hashes) / 20    
        info['pieces'] = ' -- hashes -- '   
    
        pprint.pprint ( metainfo, stream=tmpStream, indent=4 )
    
        torrentInfo = '%s\nSHA1 info_hash: %s' % (tmpStream.getvalue(), infoHash )
        
        return ( infoHash, fils, torrentInfo )

    except:
        return None        



def retrieveTorrent ( torrentFile, infoHash, filelist, torrentDir ):
    global TORRENT_PATH
    
    dlog (1, 'Retrieving %s into %s' % ( torrentFile, torrentDir) )
    
    # TODO: check current state of rtorrent. 
    #   filespace OK? up/download speeds OK? etc.

    comlist = ['rtxmlrpc', 'download_list', 'complete' ]
    ret = shellCommand (comlist)
    completeList = ret[1].rsplit( '\n' )  
    if infoHash in completeList:
        dlog (1, 'SKIPPED torrent already finished' )
        return 0     

    comlist = ['rtxmlrpc', 'download_list', 'incomplete' ]
    ret = shellCommand (comlist)
    incompleteList = ret[1].rsplit( '\n' )  
    if infoHash in incompleteList:
        dlog (1, 'SKIPPED: torrent currently in process' )
        return 0        
    
    # set the directory in which the torrent will download
    # note that each torrent goes into its own subdirectory
    comlist = ['rtxmlrpc', 'set_directory', torrentDir ]
    ret = shellCommand (comlist)
    
    # make and operate on a copy of the torrentFile as the original is deleted by d.erase :P
    torrentFileCopy = 'tmp_' + torrentFile 
    comlist = ['cp', torrentFile, torrentFileCopy ]
    ret = shellCommand (comlist)
    
    # torrent path/fn provided must be fully qualified if not in ~
    fqpn = '%s/%s' % ( TORRENT_PATH, torrentFileCopy )
    comlist = ['rtxmlrpc', 'load', fqpn ]
    ret = shellCommand (comlist)
    comCode = ret[0]
    exitCode = ret[1].strip()
    if comCode != 0 or exitCode != '0':
        dlog (1, 'FAILED: could not load %s, exitcode %s ( %s, %s)' % (torrentFile, exitCode, ret[1], ret[2] ) )
        return 1       
          
    comlist = ['rtxmlrpc', 'd.start', infoHash ]
    ret = shellCommand (comlist)
    comCode = ret[0]
    exitCode = ret[1].strip()
    if comCode != 0 or exitCode != '0':
        dlog (1, 'FAILED: did not start %s, exitcode %s ( %s, %s)' % (torrentFile, exitCode, ret[1], ret[2] ) )
        return 1        
    
    comlist = ['rtxmlrpc', 'd.get_size_bytes', infoHash ]
    ret = shellCommand (comlist)
    comCode = ret[0]
    answer = ret[1].strip()
    dlsize = 0
    if comCode == 0:
        dlsize = int( answer )    
    dlsizeK = dlsize / 1024
    dlsizeMB = dlsizeK / 1024
    
    # wait for completion...
    finished = False
    # TODO monitor for other changes of state...!
    lt = datetime.datetime.now()    
    dlogAppend (1, '%s Downloading %s MB' % (lt, dlsizeMB ) )
    while finished is False:
        time.sleep(60)
        comlist = ['rtxmlrpc', 'd.get_complete', infoHash ]
        ret = shellCommand (comlist)
        comCode = ret[0]
        exitCode = ret[1].strip()
        if comCode == 0 and exitCode == '1':
            finished = True
        dlogAppend (1, '.' )
            
    dlog (1, '\nSUCCESS: retrieved %s into %s' % (torrentFile, torrentDir ) )

    # remove the torrent from seeding/download list
    # note that rtorrent deleted the 'tied' .torrent file, that's why we work on a copy
    comlist = ['rtxmlrpc', 'd.erase', infoHash ]
    ret = shellCommand (comlist)

    # write the list of retrieved files to .torrentcontents
    # TODO: could query rtorrent via d.get_size_files, followed by iteration using f.get_path
    #  but should we? In theory that would only rely on rtorrent's own parsing of the same data
    contFile = '%s/%scontents' % ( torentDir, torrentFile ) 
    with codecs.open( contFile, encoding='utf-8', mode=logmode ) as cfile:
        for aFile in filelist:
            # path is array expressing a dir path, last of which is fn
            #  c.f. http://www.bittorrent.org/beps/bep_0003.html
            fn = ''.join( aFile['path'] )
            fs = aFile['length']
            cfile.write ('%s,%s\n' % (fn, fs) )
            
    # finally, move the original torrent file into the downloaded directory
    comlist = ['mv', torrentFile, ('%s/.' % torrentDir ) ]
    ret = shellCommand (comlist)
    
    return 0    
    

def shellCommand (comlist):    
    p = subprocess.Popen( comlist, stderr = subprocess.STDOUT, stdout = subprocess.PIPE )
    (res, err) = p.communicate()
    exitcode = p.wait()
    ret = (exitcode, res, err)
    # print ret
    return ret
    
def rtorrentUp():
    global SCGI_SOCKET
    ret = shellCommand( ['test','-S',SCGI_SOCKET] )
    if ret[0] == 1:
        return True
    dlog( 1, 'rtorrent not detected on socket, trying to start...' )
    ret = shellCommand( ['rtorrent'] )
    time.sleep(1)
    ret = shellCommand( ['test','-S',SCGI_SOCKET] )
    return ( ret[0] == 1 )    
    
def tempDirForTorrent( infoHash ):
    global TEMP_DIR
    
    torrentDir = '%s/%s/' % (TEMP_DIR, infoHash )
    if os.access( torrentDir, os.F_OK ) is False:
        os.mkdir( torrentDir )
        dlog( 2, 'Making item directory %s' % torrentDir )
    else:
        dlog( 2, 'Found existing item directory %s' % torrentDir )
    return torrentDir
    
# Remember the Main

def main(argv=None):

    global verbose
    global dlogfile         # [verbose] logging for btget
    global retlogfile       # retry log in CSV form
    global dloglevel        # for our own logging only; 1 = terse, 2 = verbose, 3 = debugging

    global sout             # dlog prints to standard out as well

    global SCGI_SOCKET
    global TEMP_DIR
    global TORRENT_PATH 
    
    sout = False    
        
    # TODO read this out of an .ini
    SCGI_SOCKET = '~/torrent/.rtorrent/rpc.socket'
    TEMP_DIR = '/home/ximm/projects/bitty/tmp'
    TORRENT_PATH = '/home/ximm/projects/bitty'
    
    if argv is None:
        argv = sys.argv

    torrentFile = ''
    
    dryrun = False
    verbose = False
    debug = False
    retry = False
    
    logdir = "./bitlogs/"
    dlfn = None
    
    logmode = "a"
    
    for anArg in argv:
        if anArg[0] is "-":
            qual = anArg[1:len(anArg)]
            if qual == "dry":
                dryrun = True
            elif qual == "debug":
                debug = True
            elif qual == "verbose":
                verbose = True
            elif qual == "stdout":
                sout = True
            elif "retry=" in qual:
                retry = qual.split("retry=")[-1]
            elif "log=" in qual:
                dlfn = logdir + qual.split("log=")[-1]
                logmode = "a"
        else: 
            torrentFile = anArg

    if dlfn is None:
        dlfn = logdir + torrentFile + '.log'
    
    if verbose is True:
        dloglevel = 2
    else:
        dloglevel = 1
    
    if debug is True:
        dlogLevel = 3
    
    with codecs.open( dlfn, encoding='utf-8', mode=logmode ) as dlogfile:
        if torrentFile is None:
            dlog(0, 'btget: (2) no torrent file specified, aborting')
            print 'btget: (2) no torrent file specified, aborting'
            print 'Usage: btget torrentfile [-verbose] [-log=logfile] [-verbose] [-stdout]'  
            return 2
        tup = parseTorrent ( torrentFile ) # returns none on Fails        
        if tup is None:
            dlog(0, "btget: (1) problem with torrent file %s" % torrentFile)
            return 1
        else:
            # verify rtorrent is up and running (with xmlrpc suppor via pyroscope)
            if rtorrentUp() is False:
                dlog(1, "btget: (1) rtorrent not present on %s" % SCGI_SOCKET )
                dlog(1, "NOTE: this script requires rtorrent and pyroscope support for rtxmlrpc control." )
                return 1 

            infoHash = tup[0]
            filelist = tup[1]
            torrentInfo = tup[2]

            if os.access( TEMP_DIR, os.F_OK ) is False:
                os.mkdir( TEMP_DIR )
            torrentDir = tempDirForTorrent( infoHash )                           

            res = retrieveTorrent ( torrentFile, infoHash, filelist, torrentDir )
            if res == 0:
                dlog(1, "btget: (0) retrieved %s" % torrentFile)
                return 0
            elif res == 2:
                dlog(0, "btget: (1) problem with torrent file %s" % torrentFile)
                return 1
            elif res == 1:
                dlog(0, "btget: (1) failed trying to retrieve %s" % torrentFile)
                return 1
            else:
                dlog(0, "btget: (1) unknown failure retrieving %s" % torrentFile)
                return 1
                        
if __name__ == "__main__":
    sys.exit(main())
