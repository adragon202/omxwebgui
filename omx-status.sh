## check omxplayer status

ps cax | grep "omxplayer" > /dev/null
if [ $? -eq 0 ]; then
    exit 1
fi
exit 0