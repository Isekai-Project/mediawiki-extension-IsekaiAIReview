<?php
namespace Isekai\AIReview;

class SectionSplitter {
    private $chunkList = [''];
    private $chunkListSeek = 0;
    private $bufferLength = 0;
    private $maxLength;

    public function __construct($text, $maxLength = 10000){
        $this->maxLength = $maxLength;
        $this->splitLine($text);
    }

    /* 将文本推入chunk列表 */
    public function push($chunk){
        $chunkLength = mb_strlen($chunk, 'UTF-8');
        if($this->bufferLength + $chunkLength > $this->maxLength){ //满一万字
            $this->chunkListSeek ++;
            $this->chunkList[$this->chunkListSeek] = $chunk;
            $this->bufferLength = $chunkLength;
        } else { //没满一万字，接着塞
            $this->chunkList[$this->chunkListSeek] .= $chunk;
            $this->bufferLength += $chunkLength;
        }
    }

    /**
     * 按照行来拆分
     */
    public function splitLine($text){
        $text = str_replace("\r\n", "\n", $text);
        $lines = explode("\n", $text);
        foreach($lines as $line){
            if(empty($line)) continue;

            $line .= "\n";
            if(mb_strlen($line, 'UTF-8') > $this->maxLength){ //见鬼，这个人怎么能写一万字不换行
                $this->splitSentence($line);
            } else {
                $this->push($line);
            }
        }
    }

    /**
     * 按照句子来拆分
     */
    public function splitSentence($text){ //我就不信一句话能一万字
        $sentences = explode("\0", preg_replace('/(。|\\.)/', "$1\0", $text));
        foreach($sentences as $sentence){
            if(mb_strlen($sentence, 'UTF-8') > $this->maxLength){ //一句话能说一万字吗？
                $this->forceSplit($sentence);
            } else {
                $this->push($sentence);
            }
        }
    }

    /**
     * 强制拆分
     */
    public function forceSplit($text){
        $len = mb_strlen($text, 'UTF-8');
        $times = ceil($len / $this->maxLength);
        for($i = 0; $i < $times; $i ++){
            $startPos = $i * $this->maxLength;
            $sentenceLen = min($len - 1 - $i * $startPos, $this->maxLength);
            $sentence = substr($text, $startPos, $sentenceLen);
            
            $this->push($sentence);
        }
    }

    public function getChunkList(){
        return $this->chunkList;
    }
}