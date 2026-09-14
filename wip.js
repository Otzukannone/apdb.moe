// wip.js — shared background for WIP pages.
// Picks a random clip from assets/wip for the fullscreen background and
// loops the site audio if the visitor enabled "wip music" this session
// (from the main menu). The clips themselves carry no sound.
(function () {
  var clips = [
    'wip 1.webm',
    'wip 2.webm',
    'wip 3.webm',
    'wip 4.webm'
  ];
  var audioTrack = 'wip site audio.mp3';
  var SESSION_KEY = 'apdb_wip_audio';

  var video = document.getElementById('wipBg');
  if (video) {
    var pick = clips[Math.floor(Math.random() * clips.length)];
    video.src = '../assets/wip/' + encodeURIComponent(pick);
    // never let it stall: restart if the engine fires "ended" for any reason
    video.addEventListener('ended', function () {
      video.play().catch(function () {});
    });
  }

  function sessionEnabled() {
    try { return sessionStorage.getItem(SESSION_KEY) === '1'; } catch (error) { return false; }
  }

  if (sessionEnabled()) {
    var audio = new Audio('../assets/wip/' + encodeURIComponent(audioTrack));
    audio.loop = true;
    audio.volume = 0.8;

    var tryPlay = function () {
      var attempt = audio.play();
      if (attempt && attempt.catch) { attempt.catch(function () {}); }
    };
    tryPlay();

    // Browsers may refuse the first unmuted play() on a freshly loaded page
    // even though the visitor enabled music earlier this session. Keep
    // retrying on a short timer, and on any input afterwards — the moment
    // activation is available the audio starts on its own. No visible UI:
    // this exact behavior is the standard for every WIP page.
    var timer = null;
    var attempts = 0;

    timer = window.setInterval(function () {
      attempts += 1;
      if (!audio.paused || attempts >= 8) {
        window.clearInterval(timer);
        return;
      }
      tryPlay();
    }, 700);

    ['pointerdown', 'keydown', 'touchstart', 'focus', 'pageshow', 'visibilitychange'].forEach(function (type) {
      window.addEventListener(type, function () {
        if (audio.paused) { tryPlay(); }
      });
    });
  }
})();
