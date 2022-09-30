Dropseeker
---------
Transcribe podcasts to make them searchable, and then extract audio clips based on search terms.

I initially wrote this to make it easier to make drops for the Doughboys podcast.

To download and transcribe an entire podcast:

`php fetch.php --feed http://example.com/feed.xml`

To find all occurrences of someone saying "have a great day", extract the audio plus an additional second on each side, and save the clips to the directory `great-day/`,

`php search.php --search "have a great day" --extract --before 1 --after 1 --output_dir great-day/`

Requirements
------------
The transcription portion requires the audio transcription tool [whisper](https://github.com/openai/whisper). It is easy to install and free to use.

To extract clips, you'll need [ffmpeg](https://ffmpeg.org/download.html). It is also easy to install and free to use.
