import { useEffect, useState } from "react";
import { getUsers, getRoles, assignRole } from "../../../services/usersService";

export default function UsersPage() {
    const [users, setUsers] = useState<any[]>([]);
    const [roles, setRoles] = useState<any[]>([]);
    const [selectedRole, setSelectedRole] = useState<{ [key: number]: number }>({});

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        const usersRes = await getUsers();
        const rolesRes = await getRoles();

        setUsers(usersRes.data);
        setRoles(rolesRes.data);
    };

    const handleAssignRole = async (userId: number) => {
        if (!selectedRole[userId]) return alert("Seleccione un rol");

        await assignRole(userId, selectedRole[userId]);
        alert("Rol asignado");
        loadData();
    };

    return (
        <div className="p-6">
            <h1 className="text-2xl font-bold mb-4">Gestión de Usuarios</h1>

            <table className="w-full border">
                <thead className="bg-gray-100">
                    <tr>
                        <th className="border p-2">Usuario</th>
                        <th className="border p-2">Rol actual</th>
                        <th className="border p-2">Asignar rol</th>
                        <th className="border p-2">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    {users.map(user => {
                        const activeRole = user.user_roles?.find((r: any) => r.end_date === null);

                        return (
                            <tr key={user.id}>
                                <td className="border p-2">{user.name}</td>
                                <td className="border p-2">
                                    {activeRole?.role?.name || "Sin rol"}
                                </td>
                                <td className="border p-2">
                                    <select
                                        className="border p-1"
                                        onChange={e =>
                                            setSelectedRole({
                                                ...selectedRole,
                                                [user.id]: Number(e.target.value)
                                            })
                                        }
                                    >
                                        <option value="">Seleccione</option>
                                        {roles.map(role => (
                                            <option key={role.id} value={role.id}>
                                                {role.name}
                                            </option>
                                        ))}
                                    </select>
                                </td>
                                <td className="border p-2">
                                    <button
                                        onClick={() => handleAssignRole(user.id)}
                                        className="bg-blue-600 text-white px-3 py-1 rounded"
                                    >
                                        Guardar
                                    </button>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
