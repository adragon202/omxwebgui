/**
* Sending actions via ajax
*
* @param data
* @param callback
*/
function doAction(data, callback){
    $.post(window.location.href, data, function(response){
        if(typeof callback !== "undefined") callback();
        if(response.length) $(".ajax-success").show().stop(true).css("opacity", 1).fadeOut(17000).children().html(response);
    });
};

/**
* Translate
*
* @param string key
* @return string
*/
function t(key){
    if(typeof translations[language] !== "undefined" && typeof translations[language][key] !== "undefined") return translations[language][key];
    if(typeof translations["en"][key] !== "undefined") return translations["en"][key];
    return key;
}

// ajax error and success handlers
$(document).ajaxError(function(event, jqxhr, settings, thrownError){
    $(".ajax-error").show().children().html(jqxhr.responseText.replace(/\n/ig, "<br/>"));
}).on("keyup", function(ev){
    if(!$(ev.target).is("body")) return;
    var k = ev.keyCode.toString();
    for(var i in omxHotkeys){
        var s = omxHotkeys[i].key.split(",");
        if($.inArray(k, s) !== -1){
            $(".omx-buttons .button[data-shortcut='"+i+"']").trigger("click");
            break;
        }
    }
});

$(document).ready(function(){
    // async filelist load for better page performance
    $.post(window.location.href, {"action" : "get-filelist"}, function(data){
        $("#filelist").html(data);
    });

    // fetch current player status
    var fetchTimeout = null;
    var lastPath = null;
    var fetchStatus = function(){
        clearTimeout(fetchTimeout);
        $.getJSON(window.location.href.replace(/\?.*/ig, "")+"?json=1&action=get-status", function(data){
            $(".files .file").removeClass("active");
            switch(data.status){
                case "playing":
                    lastPath = data.path;
                    $("#status").html(t("Playing")+": <span class='current-file'>"+data.path+'</span>');
                    $(".files .file").filter("[data-path='"+data.path+"']").addClass("active");
                break;
                case "stopped":
                    $("#status").html(t("video.notselected"));
                    var currentFile = $(".file").filter("[data-path='"+lastPath+"']");
                    if(currentFile.length && currentFile.next().length && lastPath && options["autoplay-next"] === "1"){
                        lastPath = null;
                        currentFile.next().trigger("click");
                    }
                break;
            }
            fetchTimeout = setTimeout(fetchStatus, 1500);
        });
    };
    fetchStatus();

    $(document).on("click", ".action[data-action]", function(ev){
        var el = $(ev.currentTarget);
        switch(el.attr("data-action")){
            case "toggle-next":
                el.next().toggle();
                break;
            case "save-options":
                var data = $(document.opt).serialize();
                doAction(data);
                break;
        }
    }).on("click", ".files .file", function(ev){
        $("#status").html(t("loading")+"...");
        doAction({"action" : "shortcut", "shortcut" : "start", "path" : $(this).attr("data-path")});
    }).on("click", ".omx-buttons .button[data-shortcut]", function(ev){
        doAction({"action" : "shortcut", "shortcut" : $(this).attr("data-shortcut"), "path" : $(".current-file").text()});
    });

    // search handler
    $(".search").on("focus", function(){
        if(!$(this).attr("data-value")) $(this).attr("data-value", this.value);
        this.value = "";
    }).on("keyup blur", function(ev){
        if(ev.keyCode == 27){
            this.value = "";
            $(this).blur();
            return;
        }
        var v = this.value.trim();
        if(v.length <= 1){
            $(".results .file").show().each(function(){
                $(this).html($(this).attr("data-path"));
            });
            if(v.length == 0 && ev.type == "blur") $(this).val($(this).attr("data-value"));
            return;
        }
        var s = v.trim().split(" ");
        var sRegex = s;
        for(var i in sRegex) {
            sRegex[i] = {"regex" : new RegExp(sRegex[i].replace(/[^0-9a-z\/\*]/ig, "\\$&").replace(/\*/ig, ".*?"), "ig"), "val" : s[i]};
        }
        $(".results .file").hide().each(function(){
            var f = $(this);
            var p = f.attr("data-path");
            var html = p;
            var matches = [];
            sRegex.forEach(function(val){
                var m = p.match(val.regex);
                if(p.match(val.regex)){
                    f.show();
                    matches.push(m[0]);
                    html = html.replace(val.regex, "_"+(matches.length - 1)+"_");
                }
            });
            for(var i in matches){
                html = html.replace(new RegExp("_"+i+"_", "ig"), '<span class="match">'+matches[i]+'</span>');
            }
            f.html(html);
        });
    });
    // toggle filelist
    $(".files .status-line").on("click", function(){
        $(".files .results").toggle();
    });
});