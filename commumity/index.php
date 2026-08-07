<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Halaman Utama dengan Popup Chat</title>
<style>

.chat-button {
  position: fixed;
  bottom: 25px;
  right: 25px;
  background: linear-gradient(45deg, #6a11cb, #2575fc);
  color: white;
  border: none;
  border-radius: 50%;
  width: 60px;
  height: 60px;
  font-size: 28px;
  cursor: pointer;
  box-shadow: 0 4px 10px rgba(0,0,0,0.3);
  display: flex;
  justify-content: center;
  align-items: center;
}
.chat-popup {
  position: fixed;
  bottom: 90px;
  right: 25px;
  width: 380px;
  height: 480px;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 4px 15px rgba(0,0,0,0.2);
  display: none;
  flex-direction: column;
  overflow: hidden;
}
.chat-popup iframe {
  width: 100%;
  height: 100%;
  border: none;
  border-radius: 10px;
}
.chat-popup-header {
  background: linear-gradient(45deg, #6a11cb, #2575fc);
  color: white;
  padding: 8px 12px;
  font-weight: bold;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.chat-popup-header button {
  background: transparent;
  border: none;
  color: white;
  font-size: 18px;
  cursor: pointer;
}
</style>
</head>
<body>

<h1>Selamat Datang di Website Kami 👋</h1>
<p>Klik tombol di kanan bawah untuk membuka chat komunitas.</p>







</body>
</html>
