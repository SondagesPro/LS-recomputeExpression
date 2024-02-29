/*
 * JavaScript functions for recomputeExpression Plugin for LimeSurvey
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2013 Denis Chenu <http://sondages.pro>
 * @copyright 2013 Practice Lab <https://www.practicelab.com/>
 * @license GPL v3
 * @version 1.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
 
$(document).on('ready pjax:scriptcomplete', function() {

    console.log("rec var", recomputeVar);
    if(typeof recomputeVar!='undefined'){
        addUpdateResponse();
    }
});

function addUpdateResponse()
{
    var docUrl=document.URL;
    var jsonUrl=recomputeVar.jsonurl;
    var aUrl=docUrl.split('/');
    var surveyid=recomputeVar.surveyId;
    var responseId=recomputeVar.responseId;
    var isResponsePage = recomputeVar.isResponsePage;
    var route = recomputeVar.route;
    console.warn([
        surveyid,
        responseId,
        $('table.detailbrowsetable').length
    ]);
    if (route === "responses/view" && isResponsePage && responseId) {
      // Browse one response
      // OR var responseId= aUrl.pop();
      if (
        $(".ls-topbar-buttons:last").length &&
        $(".updateanswer").length === 0
      ) {
        $(".ls-topbar-buttons:last").append(
          "<a class='btn btn-primary updateanswer' role='button' data-responseid='" +
            responseId +
            "'><i class='ri-restart-line'></i> Update This Answer</a>"
        );
      }
      $(".updateanswer").click(function () {
        $("#updatedsrid").remove();
        $.ajax({
          url: jsonUrl,
          dataType: "json",
          data: { sid: surveyid, srid: responseId },
          success: function (data) {
            var $dialog = $('<div id="updatedsrid"></div>')
              .html("<p>" + data.message + "</p>")
              .dialog({
                title: data.status,
                dialogClass: "updatedsrid",
                buttons: {
                  Ok: function () {
                    $(this).dialog("close");
                  },
                  Reload: function () {
                    window.location.reload();
                  },
                },
                modal: true,
                close: function () {
                  $(this).remove();
                },
              });
          },
          error: function (err) {
            console.log(err);
            var $dialog = $('<div id="updatedsrid"></div>')
              .html("<p>An error was occured</p>" + err)
              .dialog({
                title: "Error",
                dialogClass: "updatedsrid",
                buttons: {
                  Ok: function () {
                    $(this).dialog("close");
                  },
                },
                modal: true,
                close: function () {
                  $(this).remove();
                },
              });
          },
        });
      });
      return;
    } else if (route === "responses/browse" && isResponsePage && surveyid) {
      if (
        $(".ls-topbar-buttons:last").length &&
        $(".updateanswer").length === 0
      ) {
        $(".ls-topbar-buttons:last").append(
          "<a class='btn btn-primary updateanswer' role='button' data-recompute='1'><i class='ri-restart-line'></i>Update All Answer</a>"
        );
      }
      $("[data-recompute]").click(function () {
        $("#updatedsrid").remove();
        var $dialog = $(
          '<div id="updatedsrid" style="overflow-y:scroll"></div>'
        )
          .html("")
          .dialog({
            height: 200,
            title: "Status",
            dialogClass: "updatedsrid",
            buttons: {
              Cancel: function () {
                $(this).dialog("close");
              },
            },
            modal: true,
            close: function () {
              $(this).remove();
            },
          });
        loopUpdateResponse(jsonUrl, surveyid, 0);
      });
    }
}

/*
* Used to update response one by one
* @param jsonurl : The json Url to request
* @param {integer} surveyid : The survey id
* @param {integer} responseid : The response id
*/
function loopUpdateResponse(jsonurl,surveyid,responseid) {
  if($("#updatedsrid").length>0)
  {
    $.ajax({
        url: jsonurl,
        dataType : 'json',
        data : {sid: surveyid, srid: responseid,next : 1},
        success: function (data) {
          $("#updatedsrid").prepend("<p style='margin:0;display:none'>"+data.message+"</p>");
          $("#updatedsrid p:first-child").slideDown(500);
          //$("#updatedsrid p:nth-child(6)").fadeOut(500,function() {$(this).remove();});
            if (data.next) {
                loopUpdateResponse(jsonurl,surveyid,data.next);
            } else {
              $("#updatedsrid").closest(".ui-dialog").find(" .ui-dialog-buttonset .ui-button-text").html("Done");
              $("#updatedsrid").prepend("<p style='margin:0;font-weight:700'>Done</p>");
            }
        },
        error: function(){
          $("#updatedsrid").prepend("<p style='margin:0;display:none'>An error was occured</p>");
          $("#updatedsrid p:first-child").slideDown(500);
          $("#updatedsrid").closest(".ui-dialog").find(" .ui-dialog-buttonset button").html("Done");
          $("#updatedsrid").prepend("<p style='margin:0;font-weight:700'>Done</p>");
        }
    });
  }
}
