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

const server = http.createServer(app);
const io = new Server(server, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

const PORT = process.env.SIGNALING_PORT || 3000;

// Store active call rooms
const activeCalls = new Map();

io.on('connection', (socket) => {
    console.log('User connected:', socket.id);

    // Join a personal room for targeted notifications
    const userId = socket.handshake.query.userId;
    if (userId) {
        console.log(`User ${userId} joined their personal room`);
        socket.join(`user_${userId}`);
    }

    socket.on('call:start', (data) => {
        const { appointment_id, sender_id, receiver_id } = data;
        console.log(`Call started for appointment ${appointment_id} by ${sender_id}`);
        socket.join(`appointment_${appointment_id}`);
        socket.to(`appointment_${appointment_id}`).emit('call:start', data);
    });

    socket.on('call:join', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        const sender_id = data.sender_id || data.userId;
        console.log(`User ${sender_id} joined call ${appointment_id}`);
        const roomName = `appointment_${appointment_id}`;
        socket.currentRoom = roomName;
        socket.join(roomName);
        socket.to(roomName).emit('call:join', data);
        
        // Notify the joining user if they are the second person in the room
        const room = io.sockets.adapter.rooms.get(roomName);
        if (room && room.size > 1) {
            socket.emit('call:ready', { appointmentId: appointment_id });
        }
    });

    socket.on('webrtc:signal', (data) => {
        const appointment_id = data.appointment_id || data.appointmentId;
        // If appointment_id is missing, we might have to rely on socket rooms
        // But WebRTCCall.tsx should probably send it.
        // Let's check what WebRTCCall.tsx sends.
        socket.to(`appointment_${appointment_id}`).emit('webrtc:signal', data);
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
        io.to(`appointment_${appointment_id}`).emit('call:end', data);
        socket.leave(`appointment_${appointment_id}`);
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
    const appointment_id = data.appointment_id || data.appointmentId;
    const receiver_id = data.receiver_id || data.receiverId;
    
    if (event) {
        if (appointment_id) {
            console.log(`Broadcasting ${event} to appointment_${appointment_id}`);
            io.to(`appointment_${appointment_id}`).emit(event, data);
        }
        
        if (receiver_id) {
            console.log(`Broadcasting ${event} to user_${receiver_id}`);
            io.to(`user_${receiver_id}`).emit(event, data);
        }
        
        return res.json({ status: 'sent' });
    }
    res.status(400).json({ status: 'failed' });
});

server.listen(PORT, '0.0.0.0', () => {
    console.log(`Signaling server running on port ${PORT}`);
});
