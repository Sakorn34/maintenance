// When the user scrolls the page, execute myFunction
window.onscroll = function() {myFunction()};
function myFunction() {
  var bar = document.getElementById("myBar");
  if (!bar) {
    return;
  }
  var winScroll = document.body.scrollTop || document.documentElement.scrollTop;
  var height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
  if (height <= 0) {
    return;
  }
  var scrolled = (winScroll / height) * 100;
  bar.style.width = scrolled + "%";
}

var TableBackgroundNormalColor = "#ffffff";
var TableBackgroundMouseoverColor = "#E4F3D3";  /* D7FFE6 */
function ChangeHoverBGColor(row) { row.style.backgroundColor = TableBackgroundMouseoverColor; }
function RestoreHoverBGColor(row) { row.style.backgroundColor = TableBackgroundNormalColor; }