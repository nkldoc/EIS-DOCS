$(document).ready(function () {
  if (window.NMUNotificationSocket) return;
  function safeText(value) { return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  let user_data_socket = Ext.getInfo;
  user_data_socket["mode"] = "USER_CONNECT";
  user_data_socket["datetime"] = DateTimeNow();
  user_data_socket["date"] = user_data_socket["datetime"].split(" ")[0];
  user_data_socket["time"] = user_data_socket["datetime"].split(" ")[1];

  let socket = io("https://" + Ext.getInfo.serverName + ":4000", {
  // let socket = io("http://" + Ext.getInfo.serverName + ":4000", {
    query: user_data_socket,
    timeout: 8000, reconnectionDelay: 3000, reconnectionDelayMax: 30000, randomizationFactor: 0.2,
  });

  window.NMUNotificationSocket = socket;
  socket.on('connect', function () { socket.emit('onConnection', JSON.stringify(Ext.getInfo)); });
  window.addEventListener('pagehide', function () { socket.disconnect(); });
  window.addEventListener('pageshow', function (event) { if (event.persisted) socket.connect(); });
  socket.on("onDataUser", function (result) {
    result.data.sort((a, b) => b.status - a.status);
    let li = "";
    if (result.success == true) {
      result.data.forEach((element) => {
        if (user_data_socket["date"] == element.date) {
          li +=
            "<li>" +
            "<div style='position: relative;'>" +
            "<div style='float:left;'>" +
            "<img src='images/ic-user.jpg'>" +
            "<div class='sp-status " +
            (element.status == 1 ? "sp-online" : "sp-offline") +
            "'></div>" +
            "<span class='sp-username'>" +
            " " +
            safeText(element.name) +
            "</span>" +
            "<span class='sp-costname'>" +
            safeText(element.costname) +
            "</span>" +
            "</div>" +
            "<div class='sp-position'>" +
            safeText(element.time) +
            "</div>" +
            "<div style='clear:both;'></div>" +
            "</li>";
        }
      });
      setTimeout(function () {
        $(".div-online").empty();
        $(".div-online").html("<ul>" + li + "</ul>");
      }, 1000);
    }
  });

  let nt = "";
  socket.on("Notify", function (result) {
    const user = result.data.dc_user_id.find((dc_user_id) => dc_user_id == Ext.getInfo.sysUserId);
    if (user) {
      var date = new Date(result.data.date);
      var options = { year: "numeric", month: "short", day: "numeric" };
      var DateTime = DateTimeDisplay(user_data_socket["date"], result.data.date, result.data.time);
      nt =
        "<li>" +
        "<div style='position: relative;'>" +
        "<div style='float:left;'>" +
        "<span class='nt-title'>" +
        "<div class='nt-status nt-new'></div>" +
        safeText(result.data.title) +
        "</span>" +
        "<span class='nt-msg'>" +
        safeText(result.data.msg) +
        "</span>" +
        "</div>" +
        "<div class='nt-datetime'>" +
        DateTime +
        "</div>" +
        "<div style='clear:both;'></div>" +
        "</li>" +
        nt;
      $(".div-notifyUser").empty();
      $(".div-notifyUser").html("<ul>" + nt + "</ul>");
      var reddot_num = (nt.match(/<div\s+class=['"]nt-status\s+nt-new['"]><\/div>/g) || []).length;
      // Ext.getCmp("TreePanel3ID").setTitle("แจ้งเตือน " + "<font  style='color: #FF7C81; font-size: 12px;'>⬤" + reddot_num + "</font>");
      Ext.getCmp("TreePanel3ID").setTitle("แจ้งเตือน " +
        "<span style='position: relative; display: inline-block; width: 16px; height: 16px; background-color: #FF7C81; border-radius: 50%; color: white; font-size: 10px; line-height: 16px; text-align: center; font-weight: bold;'>" +
          reddot_num +
        "</span>"
      );
      showToast("🔔 มีการแจ้งเตือนใหม่ถึงคุณ ");
      // var msg = result.data.msg.replaceAll("<br>","\n");
      // msg = msg.replaceAll("</br>","\n");
      // msg = msg.replaceAll("<b>","");
      // msg = msg.replaceAll("</b>","");

      // if ("Notification" in window) {
      //   Notification.requestPermission().then(function (permission) {
      //     if (permission === "granted") {
      //       var notification = new Notification(result.data.title, {
      //         body: msg,
      //         data: { url: "https://example.com" }
      //       });
      
      //       setTimeout(notification.close.bind(notification), 5000);
      //     }
      //   });
      // } else {
      //   console.log("This browser does not support notifications.");
    
      // }
    }
  });

  function Notifyload() {
    var random = Math.floor(Math.random() * 100000)
      .toString()
      .padStart(6, "0");
    const filePath = "../NMU/tmp/tmpNotify/tmpNotify_" + Ext.getInfo.sysUserId + ".json?randow=" + random;
    const filePath_time = "../NMU/tmp/tmpNotify/tmpNotifyReadtime.json?randow=" + random;
    var time_read_last = "2000-01-01 00:00";
    var reddot = false;
    var reddot_num = 0;
    $.getJSON(filePath_time, function (json) {
      var result = json.find((user) => user.dc_user_id == Ext.getInfo.sysUserId);
      if (result) {
        time_read_last = result.date + " " + result.time;
      }
      $.getJSON(filePath, function (json) {
        var i = 0;
        json.forEach((element) => {
          if (new Date(time_read_last) <= new Date(element.date + " " + element.time)) {
            reddot = true;
            ++reddot_num;
          }
          if (++i <= 10) {
            var DateTime = DateTimeDisplay(user_data_socket["date"], element.date, element.time);
            // var date = new Date(element.date);
            // var options = { year: "numeric", month: "short", day: "numeric" };
            // var thaiDate = date.toLocaleDateString("th-TH", options);

            nt +=
              "<li>" +
              "<div style='position: relative;'>" +
              "<div style='float:left;'>" +
              "<span class='nt-title'>" +
              (new Date(time_read_last) <= new Date(element.date + " " + element.time) ? "<div class='nt-status nt-new'></div>" : "") +
              safeText(element.title) +
              "</span>" +
              "<span class='nt-msg'>" +
              safeText(element.msg) +
              "</span>" +
              "</div>" +
              "<div class='nt-datetime'>" +
              DateTime +
              "</div>" +
              "<div style='clear:both;'></div>" +
              "</li>";
          }
        });
        if (json.length > 10) {
          nt +=
            "<li>" +
            "<div style='position: relative;'>" +
            "<div style='text-align: center; font-size: 13px; '>" +
            // '<a href="api/List_notifyUser.php" target="UserDataNotify" style="text-decoration: none; color: #003AB1; cursor: pointer">แสดงการแจ้งเตือนทั้งหมด</a>' +
            '<a href="api/All_notifyUser.php" target="UserDataNotify" style="text-decoration: none; color: #003AB1; cursor: pointer">แสดงการแจ้งเตือนทั้งหมด</a>' +
            "</div>" +
            "</div>" +
            "</li>";
        }
      });
    });
    setTimeout(function () {
      $(".div-notifyUser").empty();
      $(".div-notifyUser").html("<ul>" + nt + "</ul>");
      // if (reddot) Ext.getCmp("TreePanel3ID").setTitle("แจ้งเตือน " + "<font  style='color: #FF7C81; font-size: 12px;'>⬤" + reddot_num + "</font>");
      if (reddot){
        Ext.getCmp("TreePanel3ID").setTitle("แจ้งเตือน " +
          "<span style='position: relative; display: inline-block; width: 16px; height: 16px; background-color: #FF7C81; border-radius: 50%; color: white; font-size: 10px; line-height: 16px; text-align: center; font-weight: bold;'>" +
            reddot_num +
          "</span>"
        );
      }
    }, 1000);
  }
  Notifyload();

  function showToast(message) {
    var toast = document.getElementById("toast");
    toast.innerText = message;
    toast.style.opacity = 1;
    setTimeout(function () {
      toast.style.opacity = 0;
    }, 4000);
  }

  function DateTimeDisplay(DateNow, DateData, Time) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(DateData)) || !/^\d{2}:\d{2}(?::\d{2})?$/.test(String(Time))) return safeText(String(DateData) + ' ' + String(Time));
    var arr_DateNow = DateNow.split("-");
    var arr_DateData = DateData.split("-");
    var date = new Date(DateNow);
    date.setDate(date.getDate() - 1);
    var Yesterday = date.toISOString().slice(0, 10);
    var MM = "";
    var setDate = "";

    if (DateNow == DateData) {
      setDate = Time + " น.";
    } else if (Yesterday == DateData) {
      setDate = "เมื่อวาน<br>" + Time + " น.";
    } else {
      if (arr_DateData[1] == "01") {
        MM = "ม.ค.";
      } else if (arr_DateData[1] == "02") {
        MM = "ก.พ.";
      } else if (arr_DateData[1] == "03") {
        MM = "มี.ค.";
      } else if (arr_DateData[1] == "04") {
        MM = "เม.ย.";
      } else if (arr_DateData[1] == "05") {
        MM = "พ.ค.";
      } else if (arr_DateData[1] == "06") {
        MM = "มิ.ย.";
      } else if (arr_DateData[1] == "07") {
        MM = "ก.ค.";
      } else if (arr_DateData[1] == "08") {
        MM = "ส.ค.";
      } else if (arr_DateData[1] == "09") {
        MM = "ก.ย.";
      } else if (arr_DateData[1] == "10") {
        MM = "ต.ค.";
      } else if (arr_DateData[1] == "11") {
        MM = "พ.ย.";
      } else if (arr_DateData[1] == "12") {
        MM = "ธ.ค.";
      }
      if (arr_DateNow[0] == arr_DateData[0]) {
        setDate = arr_DateData[2] + " " + MM + "<br>" + Time + " น.";
      } else {
        var YY = String(parseInt(arr_DateData[0]) + 543);
        YY = YY.slice(-2);
        setDate = arr_DateData[2] + " " + MM + " " + YY + "<br>" + Time + " น.";
      }
    }
    return setDate;
  }
});
// socket.on("onDisConnection", function (msg) {
// socket.emit("onConnection", JSON.stringify(Ext.getInfo));
// });
