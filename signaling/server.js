const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
require('dotenv').config({ path: __dirname + '/../.env' });

const app = express();
app.use(cors());
app.use(express.json());
app.use((req, res, next) => {
    console.log(`${new Date().toISOString()} - ${req.method} ${req.url}`);
    next();
});

app.get('/', (req, res) => res.send('Signaling Server is Running'));
app.get('/health', (req, res) => res.json({ status: 'ok', port: PORT }));

const PORT = process.env.PORT || process.env.SIGNALING_PORT || 3000;

const server = http.createServer(app);
const io = new Server(server, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    },
    pingInterval: 10000,
    pingTimeout: 5000,
    transports: ['websocket', 'polling']
});

// Store active call rooms
const activeCalls = new Map();

io.on('connection', (socket) => {
    console.log('User connected:', socket.id);

    // Join a personal room for targeted notifications
    const userId = socket.handshake.query.userId;
    const appointmentId = socket.handshake.query.appointmentId;
    if (userId) {
        console.log(`User ${userId} joined their personal room`);
        socket.join(`user_${userId}`);
    }
    if (appointmentId) {
        console.log(`Socket joined appointment_${appointmentId} via handshake query`);
        socket.join(`appointment_${appointmentId}`);
        socket.currentRoom = `appointment_${appointmentId}`;
    }


    socket.on('call:start', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        const sender_id = data.sender_id || data.userId;
        const receiver_id = data.receiver_id || data.receiverId;
        console.log(`Call started for appointment ${appointment_id} by ${sender_id} targeting receiver ${receiver_id}`);
        const roomName = `appointment_${appointment_id}`;
        socket.join(roomName);
        socket.to(roomName).emit('call:start', data);
        if (receiver_id) {
            io.to(`user_${receiver_id}`).emit('call:start', data);
        }
    });

    socket.on('call:join', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        const sender_id = data.sender_id || data.userId;
        const receiver_id = data.receiver_id || data.receiverId;
        console.log(`User ${sender_id} joined call room appointment_${appointment_id}`);
        const roomName = `appointment_${appointment_id}`;
        socket.currentRoom = roomName;
        socket.join(roomName);
        
        // Notify others in room
        socket.to(roomName).emit('call:join', data);
        
        // Alert receiver if outside room
        if (receiver_id) {
            io.to(`user_${receiver_id}`).emit('call:start', data);
        }

        // Notify entire room if both participants are present
        const room = io.sockets.adapter.rooms.get(roomName);
        if (room && room.size >= 2) {
            console.log(`✨ Call room ${roomName} now has ${room.size} participants. Broadcasting call:ready.`);
            io.to(roomName).emit('call:ready', { appointmentId: appointment_id });
        }
    });

    socket.on('webrtc:signal', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        const signalType = data.signal ? data.signal.type : 'unknown';
        console.log(`📡 Relaying WebRTC signal (${signalType}) for appointment_${appointment_id}`);
        if (appointment_id) {
            socket.to(`appointment_${appointment_id}`).emit('webrtc:signal', data);
        }
    });

    socket.on('call:offer', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        console.log(`Relaying offer for appointment ${appointment_id}`);
        socket.to(`appointment_${appointment_id}`).emit('call:offer', data);
    });

    socket.on('call:answer', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        console.log(`Relaying answer for appointment ${appointment_id}`);
        socket.to(`appointment_${appointment_id}`).emit('call:answer', data);
    });

    socket.on('call:ice-candidate', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        socket.to(`appointment_${appointment_id}`).emit('call:ice-candidate', data);
    });

    socket.on('call:end', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        console.log(`Call ended for appointment ${appointment_id}`);
        if (appointment_id) {
            io.to(`appointment_${appointment_id}`).emit('call:end', data);
            socket.leave(`appointment_${appointment_id}`);
        }
    });

    // Realtime Chat Events
    socket.on('chat:join', (data) => {
        const chatId = data.chat_id || data.chatId;
        if (chatId) {
            socket.join(`chat_${chatId}`);
            console.log(`Socket ${socket.id} joined chat_${chatId}`);
        }
    });

    socket.on('chat:leave', (data) => {
        const chatId = data.chat_id || data.chatId;
        if (chatId) {
            socket.leave(`chat_${chatId}`);
            console.log(`Socket ${socket.id} left chat_${chatId}`);
        }
    });

    socket.on('chat:message', (data) => {
        const chatId = data.chat_id || data.chatId;
        const receiverId = data.receiver_id || data.receiverId;
        console.log(`💬 Chat message relayed for chat ${chatId} to receiver ${receiverId}`);
        
        if (chatId) {
            socket.to(`chat_${chatId}`).emit('chat:message', data);
        }
        if (receiverId) {
            io.to(`user_${receiverId}`).emit('chat:message', data);
        }
    });

    socket.on('chat:typing', (data) => {
        const chatId = data.chat_id || data.chatId;
        const receiverId = data.receiver_id || data.receiverId;
        if (chatId) {
            socket.to(`chat_${chatId}`).emit('chat:typing', data);
        }
        if (receiverId) {
            io.to(`user_${receiverId}`).emit('chat:typing', data);
        }
    });

    socket.on('disconnecting', () => {
        console.log(`User ${socket.id} disconnecting from rooms:`, socket.rooms);
        for (const room of socket.rooms) {
            if (room.startsWith('appointment_')) {
                socket.to(room).emit('call:end', { reason: 'disconnect', socketId: socket.id });
            }
        }
    });

    socket.on('disconnect', () => {
        console.log('User disconnected:', socket.id);
    });
});

// Endpoint for Laravel to broadcast events
app.post('/broadcast', (req, res) => {
    const { event, data } = req.body;
    if (!event || !data) {
        return res.status(400).json({ status: 'failed', message: 'Missing event or data' });
    }

    const appointment_id = data.appointment_id || data.appointmentId;
    const receiver_id = data.receiver_id || data.receiverId;
    const chat_id = data.chat_id || data.chatId;
    
    if (appointment_id) {
        console.log(`📢 Broadcasting ${event} to appointment_${appointment_id}`);
        io.to(`appointment_${appointment_id}`).emit(event, data);
    }
    
    if (receiver_id) {
        console.log(`📢 Broadcasting ${event} to user_${receiver_id}`);
        io.to(`user_${receiver_id}`).emit(event, data);
    }

    if (chat_id) {
        console.log(`📢 Broadcasting ${event} to chat_${chat_id}`);
        io.to(`chat_${chat_id}`).emit(event, data);
    }
    
    return res.json({ status: 'sent' });
});

server.listen(PORT, '0.0.0.0', () => {
    console.log(`Signaling server running on port ${PORT}`);
});
